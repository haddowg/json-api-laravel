<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * The server- and type-keyed registry of declarative response-header config (PLAN decision 12,
 * bundle ADR 0054): each JSON:API type's {@see CacheHeaders} (resource-level +
 * per-operation overrides) and {@see DeprecationHeaders}, layered over the global
 * defaults from `jsonapi.defaults.cache_headers` / `jsonapi.defaults.deprecation` /
 * `…sunset`.
 *
 * Built from a plain scalar `type → {cache, cache_operations, deprecation}` map the
 * service provider projects from each discovered resource's `#[AsJsonApiResource(cacheHeaders: …)]`
 * attribute params (kept as scalars so the discovery snapshot stays cacheable). The
 * global defaults arrive as the same scalar shape from config.
 *
 * Resolution layers the resource-level value over the global default, then a
 * per-operation cache override over that — so a type tunes only what it declares. Each
 * server reads only what its own resource class declares for a type, so a server whose class
 * declares nothing gets the global defaults (ADR 0052).
 * The {@see \haddowg\JsonApiLaravel\Http\ResponseHeadersMiddleware} queries it per
 * request.
 */
final class ResponseHeadersRegistry
{
    private readonly CacheHeaders $defaultCache;

    private readonly DeprecationHeaders $defaultDeprecation;

    /** @var array<string, array<string, CacheHeaders>> */
    private array $cacheByType = [];

    /** @var array<string, array<string, array<string, CacheHeaders>>> */
    private array $cacheByTypeOperation = [];

    /** @var array<string, array<string, DeprecationHeaders>> */
    private array $deprecationByType = [];

    /**
     * @param array<string, array{
     *     cache?: array<string, mixed>,
     *     cache_operations?: array<string, array<string, mixed>>,
     *     deprecation?: array<string, mixed>,
     * }> $byType the `default` server's per-type scalar config
     * @param array<string, mixed> $defaultCache the global `cache_headers` default
     * @param array<string, mixed> $defaultDeprecation the global `deprecation`/`sunset` default
     * @param array<string, array<string, array{
     *     cache?: array<string, mixed>,
     *     cache_operations?: array<string, array<string, mixed>>,
     *     deprecation?: array<string, mixed>,
     * }>> $byServer the per-type scalar config keyed by server name, then type, as the service provider projected it from discovery; an entry for `default` here replaces `$byType`
     */
    public function __construct(array $byType = [], array $defaultCache = [], array $defaultDeprecation = [], array $byServer = [])
    {
        /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $defaultCache */
        $this->defaultCache = CacheHeaders::fromArray($defaultCache);
        /** @var array{deprecation?: bool|string|null, sunset?: string|null, sunset_link?: string|null} $defaultDeprecation */
        $this->defaultDeprecation = DeprecationHeaders::fromArray($defaultDeprecation);

        foreach ($byServer + [ServerRegistry::DEFAULT_SERVER => $byType] as $server => $types) {
            foreach ($types as $type => $config) {
                if (isset($config['cache'])) {
                    /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $cache */
                    $cache = $config['cache'];
                    $this->cacheByType[$server][$type] = CacheHeaders::fromArray($cache);
                }

                foreach ($config['cache_operations'] ?? [] as $operation => $override) {
                    /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $override */
                    $this->cacheByTypeOperation[$server][$type][$operation] = CacheHeaders::fromArray($override);
                }

                if (isset($config['deprecation'])) {
                    /** @var array{deprecation?: bool|string|null, sunset?: string|null, sunset_link?: string|null} $deprecation */
                    $deprecation = $config['deprecation'];
                    $this->deprecationByType[$server][$type] = DeprecationHeaders::fromArray($deprecation);
                }
            }
        }
    }

    /**
     * The resolved {@see CacheHeaders} for `$type` on the `$operation` read shape of
     * `$server` (null is `default`): the per-operation override (when declared) layered
     * over the resource-level value layered over the global default. Returns `null` only
     * when the result is empty (no caching anywhere) so the middleware can skip the type
     * entirely.
     */
    public function cacheFor(string $type, ResponseHeaderOperation $operation, ?string $server = null): ?CacheHeaders
    {
        $server ??= ServerRegistry::DEFAULT_SERVER;
        $resolved = ($this->cacheByType[$server][$type] ?? new CacheHeaders())->mergeOver($this->defaultCache);

        $override = $this->cacheByTypeOperation[$server][$type][$operation->value] ?? null;
        if ($override !== null) {
            $resolved = $override->mergeOver($resolved);
        }

        return $resolved->isEmpty() ? null : $resolved;
    }

    /**
     * The resolved {@see DeprecationHeaders} for `$type` on `$server` (null is `default`):
     * the resource-level value layered over the global default. Returns `null` when the
     * result is empty (no deprecation/sunset anywhere).
     */
    public function deprecationFor(string $type, ?string $server = null): ?DeprecationHeaders
    {
        $resolved = ($this->deprecationByType[$server ?? ServerRegistry::DEFAULT_SERVER][$type] ?? new DeprecationHeaders())->mergeOver($this->defaultDeprecation);

        return $resolved->isEmpty() ? null : $resolved;
    }
}
