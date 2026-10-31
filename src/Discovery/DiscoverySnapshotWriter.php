<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApiLaravel\Eloquent\ModelMapResolver;

/**
 * Writes (and clears) the discovery snapshot the {@see Discovery} loader consumes —
 * the WRITE side of the Phase-0 snapshot cache, driven by the optimize pipeline (PLAN
 * decision 11). The loader already reads a `require`-able PHP file at
 * `jsonapi.discovery.cache`; this warmer produces it, so a `route:cache`d production app
 * registers routes + assembles servers from the cached snapshot with no filesystem walk.
 *
 * It writes only the plain, `var_export`-able snapshot the loader expects — the resource
 * descriptors as array forms plus the discovered provider/persister/translator and
 * described-error class-strings — so a cached configuration is behaviourally identical to
 * a live scan. It also writes the resolved Eloquent `type → model` map with the namespace
 * it was resolved under, so a request served from the snapshot runs no inflection
 *, and each server's resolved scope table, so the scope enforcer loads it
 * rather than asking the resolver again.
 *
 * The snapshot is **opt-in**: it is written only when `jsonapi.discovery.cache` names a
 * path (null keeps the always-scan default). `php artisan optimize` writes it,
 * `optimize:clear` removes it — matching Laravel's `config:cache` / `route:cache` idiom.
 */
final class DiscoverySnapshotWriter
{
    public function __construct(
        private readonly Discovery $discovery,
        private readonly ?ModelMapResolver $models = null,
        private readonly ?\haddowg\JsonApiLaravel\Scope\ScopeTables $scopes = null,
    ) {}

    /**
     * Writes the discovery snapshot to `$path` as a `require`-able PHP file returning the
     * loader's `{resources, providers, persisters, translators, …, models}` array.
     */
    public function write(string $path): void
    {
        // Never write back the snapshot this app booted from: a resource added or changed
        // since it was written would never reach the cache.
        $this->discovery->rescan();

        $snapshot = [
            'resources' => \array_map(
                static fn(ResourceDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->resources(),
            ),
            'providers' => $this->discovery->providers(),
            'persisters' => $this->discovery->persisters(),
            'translators' => $this->discovery->translators(),
            'actions' => \array_map(
                static fn(\haddowg\JsonApiLaravel\Action\ActionDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->actions(),
            ),
            'serializers' => \array_map(
                static fn(SerializerDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->serializers(),
            ),
            'hydrators' => \array_map(
                static fn(HydratorDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->hydrators(),
            ),
            'errors' => $this->discovery->errors(),
        ];

        if ($this->models !== null) {
            // Resolved from the rescanned descriptors, not the resolver's own, which may
            // predate the rescan; resolving runs the conflict check.
            $namespace = $this->models->modelNamespace();
            $snapshot['models'] = [
                'namespace' => $namespace,
                'map' => (new ModelMapResolver($this->discovery->resources(), $namespace))->resolve(),
            ];
        }

        if ($this->scopes !== null) {
            // Built from the rescanned declarations; a resolver error fails the write.
            $snapshot['scopes'] = \array_map(
                static fn(\haddowg\JsonApi\Scope\ScopeTable $table): array => $table->toArray(),
                $this->scopes->rebuild(),
            );
        }

        $php = '<?php' . "\n\n" . 'return ' . \var_export($snapshot, true) . ';' . "\n";

        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create the discovery cache directory "%s".', $dir));
        }

        if (\file_put_contents($path, $php) === false) {
            throw new \RuntimeException(\sprintf('Could not write the discovery snapshot "%s".', $path));
        }
    }

    /**
     * Removes the discovery snapshot at `$path` (a no-op when absent).
     */
    public function clear(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }
}
