<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\OpenApi\DescribedByResolverProvider;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Resolves a configured {@see Server} by its `_jsonapi_server` route-default name.
 *
 * Multi-server support is config-declared: `jsonapi.servers` declares each server,
 * and one {@see ServerFactory} is built per declared server, each holding only that
 * server's registered resources. This registry holds a name → factory map and
 * resolves the requested server by name. An unknown name is a {@see \LogicException}
 * — a wiring fault — not a runtime `404`.
 */
final class ServerRegistry
{
    public const string DEFAULT_SERVER = 'default';

    /**
     * @param array<string, ServerFactory>          $factories   keyed by server name
     * @param ?\Closure(): ?DescribedByResolverProvider $describedBy lazy, because the provider builds documents from this registry's servers
     */
    public function __construct(
        private readonly array $factories,
        private readonly ?\Closure $describedBy = null,
    ) {}

    /**
     * The Server for `$name`, or the `default` server when `$name` is null.
     *
     * @throws \LogicException when an unknown server name is requested
     */
    public function get(?string $name = null): Server
    {
        $name ??= self::DEFAULT_SERVER;

        if (!isset($this->factories[$name])) {
            throw new \LogicException(\sprintf('No JSON:API server is configured under the name "%s".', $name));
        }

        return $this->factories[$name]->create();
    }

    /**
     * The Server for the matched route's `_jsonapi_server` default, with its links based
     * where the request found it and described by the server's OpenAPI document.
     *
     * A configured `base_uri` is returned as it is. An empty one would make core base
     * every link on the request origin alone, dropping the prefix the server's routes are
     * registered under; so when the route has a prefix (or the app is served below the
     * web root) the Server is given the effective base `origin + base path + prefix` for
     * this request instead (ADR 0028).
     *
     * The `describedby` resolver is set here rather than on the memoized Server because
     * the default document URL is generated against this request (ADR 0037).
     *
     * @throws \LogicException when the route names an unknown server
     */
    public function forRequest(Request $request): Server
    {
        $route = $request->route();
        $route = $route instanceof Route ? $route : null;

        $name = $route?->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $name = \is_string($name) && $name !== '' ? $name : self::DEFAULT_SERVER;
        $server = $this->get($name);

        $describedBy = $this->describedBy !== null ? ($this->describedBy)()?->forServer($name) : null;
        if ($describedBy !== null) {
            $server = $server->withDescribedByResolver($describedBy);
        }

        if ($server->baseUri() !== '') {
            return $server;
        }

        $path = \rtrim($request->getBaseUrl(), '/') . ($route !== null ? self::prefixOf($route) : '');
        if ($path === '') {
            return $server;
        }

        return $server->withBaseUri($request->getSchemeAndHttpHost() . $path);
    }

    /**
     * The matched route's group prefix as a path (`/api`), with any route parameter in
     * it (`{tenant}/api`) filled in from the value the request matched, before any
     * route-model binding.
     */
    private static function prefixOf(Route $route): string
    {
        $prefix = \trim((string) $route->getPrefix(), '/');
        if ($prefix === '') {
            return '';
        }

        $prefix = (string) \preg_replace_callback(
            '/\{(\w+)\??\}/',
            static function (array $match) use ($route): string {
                $value = $route->originalParameter($match[1]);

                return \is_string($value) ? \rawurlencode($value) : '';
            },
            $prefix,
        );

        $prefix = \trim((string) \preg_replace('#/{2,}#', '/', $prefix), '/');

        return $prefix === '' ? '' : '/' . $prefix;
    }
}
