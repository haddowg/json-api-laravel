<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApiLaravel\Http\OpenApiController;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Finds the route that serves each server's OpenAPI document when the package's named
 * document routes aren't registered, because the app routes {@see OpenApiController}
 * itself.
 *
 * A route serves a server's document when it is a named `GET` route whose action is the
 * controller (the invokable class, `Class@__invoke` or `[Class, '__invoke']`) and whose
 * `server` parameter is that server: a `server` default naming it, no `server` at all for
 * the default server, or a `{server}` segment whose `where` constraint accepts the name.
 * In combined mode every such route serves the one document. A route with a fixed server
 * wins over a parametric one, then the order the routes were registered in decides.
 *
 * The scan reads every route, so it runs once: the {@see DocumentRouteWarmer} writes its
 * result beside the warmed documents at `artisan optimize`, and a process without one
 * scans once and keeps the result in memory. In debug the stored result is never read,
 * so a route added between requests is found without clearing anything.
 *
 * @phpstan-type DocumentRoute array{route: string, parameters: array<string, string>}
 */
final class DocumentRouteLocator
{
    /** @var array<string, DocumentRoute|null>|null */
    private ?array $routes = null;

    /**
     * @param list<string> $servers  the declared server names
     * @param bool         $combined `jsonapi.openapi.multi_server === combined`
     * @param bool         $debug    `app.debug`
     */
    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $urls,
        private readonly ArtifactStore $store,
        private readonly array $servers,
        private readonly bool $combined = false,
        private readonly bool $debug = false,
    ) {}

    /**
     * The route serving `$server`'s document (or the combined one), or null when none does.
     *
     * @return DocumentRoute|null
     */
    public function find(string $server): ?array
    {
        return $this->routes()[$this->combined ? DocumentFactory::COMBINED_KEY : $server] ?? null;
    }

    /**
     * Scans the routes afresh and keeps the result for this process, so a document the
     * same optimize run builds afterwards links through it rather than an older stored scan.
     *
     * @return array<string, DocumentRoute|null>
     */
    public function rescan(): array
    {
        return $this->routes = $this->scan();
    }

    /**
     * The servers at least one JSON:API route is registered for (a resource, custom action or
     * Atomic Operations route), whether the package registered it or the app did through
     * `Route::jsonApi()`.
     *
     * @return list<string>
     */
    public function mountedServers(): array
    {
        $servers = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $defaults = $route->defaults;
            if (($defaults[JsonApiRoute::MARKER] ?? false) !== true) {
                continue;
            }

            $server = $defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
            $servers[\is_string($server) && $server !== '' ? $server : ServerRegistry::DEFAULT_SERVER] = true;
        }

        return \array_keys($servers);
    }

    /**
     * @return array<string, DocumentRoute|null>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        // A request never writes the scan: only the optimize step does.
        return $this->routes = ($this->debug ? null : $this->store->readDocumentRoutes()) ?? $this->scan();
    }

    /**
     * Every server's document route, keyed by server name, or by
     * {@see DocumentFactory::COMBINED_KEY} alone in combined mode.
     *
     * @return array<string, DocumentRoute|null>
     */
    private function scan(): array
    {
        $collection = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && self::servesDocument($route)) {
                $collection[$name] = $route;
            }
        }

        if ($this->combined) {
            return [DocumentFactory::COMBINED_KEY => $this->firstFor($collection, $this->servers[0] ?? ServerRegistry::DEFAULT_SERVER)];
        }

        $routes = [];
        foreach ($this->servers as $server) {
            $routes[$server] = $this->firstFor($collection, $server);
        }

        return $routes;
    }

    /**
     * @param array<string, Route> $collection the named routes to the controller
     *
     * @return DocumentRoute|null
     */
    private function firstFor(array $collection, string $server): ?array
    {
        $parametric = [];
        foreach ($collection as $name => $route) {
            if (\in_array('server', $route->parameterNames(), true)) {
                $parametric[$name] = $route;

                continue;
            }

            if (!$this->combined && self::fixedServer($route) !== $server) {
                continue;
            }

            if ($this->generates($name, [])) {
                return ['route' => $name, 'parameters' => []];
            }
        }

        $candidates = $this->combined ? $this->servers : [$server];
        foreach ($parametric as $name => $route) {
            foreach ($candidates as $candidate) {
                if (self::accepts($route, $candidate) && $this->generates($name, ['server' => $candidate])) {
                    return ['route' => $name, 'parameters' => ['server' => $candidate]];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function generates(string $name, array $parameters): bool
    {
        try {
            $this->urls->route($name, $parameters, true);

            return true;
        } catch (RouteNotFoundException | UrlGenerationException | \InvalidArgumentException) {
            return false;
        }
    }

    private static function servesDocument(Route $route): bool
    {
        if (!\in_array('GET', $route->methods(), true)) {
            return false;
        }

        $uses = $route->getAction('uses');
        if (\is_array($uses) && \count($uses) === 2 && \is_string($uses[0] ?? null) && \is_string($uses[1] ?? null)) {
            $uses = $uses[0] . '@' . $uses[1];
        }

        if (!\is_string($uses)) {
            return false;
        }

        $uses = \ltrim($uses, '\\');
        if (\str_contains($uses, '@')) {
            [$class, $method] = \explode('@', $uses, 2);
        } elseif (\str_contains($uses, '::')) {
            [$class, $method] = \explode('::', $uses, 2);
        } else {
            [$class, $method] = [$uses, '__invoke'];
        }

        return \strcasecmp(\ltrim($class, '\\'), OpenApiController::class) === 0 && \strcasecmp($method, '__invoke') === 0;
    }

    /**
     * The server a route without a `{server}` segment serves: its `server` default, else
     * the default server, as the controller resolves it.
     */
    private static function fixedServer(Route $route): string
    {
        $server = $route->defaults['server'] ?? null;

        return \is_string($server) && $server !== '' ? $server : ServerRegistry::DEFAULT_SERVER;
    }

    private static function accepts(Route $route, string $server): bool
    {
        $requirement = $route->wheres['server'] ?? '[^/]++';

        return \is_string($requirement) && \preg_match('{^(?:' . $requirement . ')$}Du', $server) === 1;
    }
}
