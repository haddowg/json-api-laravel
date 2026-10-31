<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\ServerVariable;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The OpenAPI `servers` for a server with an empty `base_uri`, read from the prefixes its
 * registered routes carry.
 *
 * Each distinct prefix becomes one relative server URL (`/api`), sorted so the document
 * doesn't depend on route order. A prefix with placeholders (`{tenant}/api`) becomes a
 * templated URL whose variables default to the route defaults; a prefix with a
 * placeholder that has no default is left out, because OAS requires a default and any
 * invented value would advertise a URL that may not exist. When no route of the server
 * has a prefix, there are no servers.
 *
 * It reads the router's routes, never a request, so the document is the same whether
 * `optimize` or the first request builds it. The app's base path below the web root is
 * only known per request and is not included.
 */
final class PrefixServers
{
    public function __construct(private readonly ?Router $router = null) {}

    /**
     * @return list<Server>
     */
    public function forServer(string $serverName): array
    {
        if ($this->router === null) {
            return [];
        }

        return self::fromRoutes($this->router->getRoutes()->getRoutes(), $serverName);
    }

    /**
     * @param iterable<Route> $routes
     *
     * @return list<Server>
     */
    public static function fromRoutes(iterable $routes, string $serverName): array
    {
        /** @var array<string, array<string, mixed>> $prefixes prefix => the defaults of its first route */
        $prefixes = [];
        foreach ($routes as $route) {
            if (($route->defaults[JsonApiRoute::MARKER] ?? false) !== true
                || ($route->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null) !== $serverName) {
                continue;
            }

            $prefix = \trim((string) $route->getPrefix(), '/');
            $prefixes[$prefix === '' ? '' : '/' . $prefix] ??= $route->defaults;
        }

        if ($prefixes === [] || \array_keys($prefixes) === ['']) {
            return [];
        }

        \ksort($prefixes, \SORT_STRING);

        $servers = [];
        foreach ($prefixes as $prefix => $defaults) {
            $server = self::serverFor((string) $prefix, $defaults);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private static function serverFor(string $prefix, array $defaults): ?Server
    {
        if ($prefix === '') {
            return new Server('/');
        }

        \preg_match_all('/\{(\w+)\??\}/', $prefix, $matches);

        $url = $prefix;
        $variables = [];
        foreach ($matches[1] as $index => $name) {
            $default = $defaults[$name] ?? null;
            if (!\is_string($default) && !\is_int($default)) {
                return null;
            }

            $url = \str_replace($matches[0][$index], '{' . $name . '}', $url);
            $variables[$name] = new ServerVariable((string) $default);
        }

        return new Server($url, variables: $variables);
    }
}
