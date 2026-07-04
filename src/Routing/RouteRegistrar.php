<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Routing;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Emits the JSON:API endpoint routes from the discovered {@see ResourceDescriptor}s,
 * operation-gated exactly as the Symfony bundle's route loader: one literal route per
 * operation a type declares, so an unexposed verb is unrouted (Laravel 404s/405s
 * natively) rather than reaching a handler that refuses.
 *
 *  - {@see Operation::FetchCollection} → `GET /{uriType}` — `{type}.index`
 *  - {@see Operation::Create}          → `POST /{uriType}` — `{type}.create`
 *  - {@see Operation::FetchOne}        → `GET /{uriType}/{id}` — `{type}.show`
 *  - {@see Operation::Update}          → `PATCH /{uriType}/{id}` — `{type}.update`
 *  - {@see Operation::Delete}          → `DELETE /{uriType}/{id}` — `{type}.delete`
 *
 * Concrete per-type literal paths are emitted (never a `/{type}` catch-all) so the
 * router natively 404s an unknown type. Every route points at the single
 * {@see JsonApiController} and carries the `_jsonapi_type` / `_jsonapi_server` defaults
 * the {@see TargetResolver} reads plus the {@see JsonApiExceptionRenderer::ROUTE_MARKER}
 * default that scopes error rendering to these routes. Route names match the bundle:
 * `jsonapi.{type}.{action}` for the default server, `jsonapi.{server}.{type}.{action}`
 * for a named server.
 *
 * A fetchable-by-id type ({@see Operation::FetchOne}) additionally gets the two parametric
 * relation GET routes — `GET /{uriType}/{id}/relationships/{relationship}` (linkage,
 * `{type}.relationship.show`) and `GET /{uriType}/{id}/{relationship}` (related resource(s),
 * `{type}.related.show`) — the linkage route first so its literal `relationships` segment is
 * never captured as a `{relationship}`. Both stay parametric; the handler enforces
 * per-relation exposure/existence as a `404`.
 *
 * Because registration is a pure function of the (memoized/cacheable) descriptor list,
 * `route:cache` is safe. The relationship MUTATION routes, custom-action + atomic routes,
 * and the `{id}` route-pattern constraint, arrive in later phases.
 */
final class RouteRegistrar
{
    /**
     * @param array<string, array{prefix?: string, middleware?: list<string>|string, domain?: string|null}> $servers the per-server route config
     */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly array $servers,
    ) {}

    /**
     * Registers every configured server's routes, each within its own prefix /
     * middleware / domain group — the automatic path the service provider drives.
     */
    public function registerConfiguredServers(Router $router): void
    {
        foreach ($this->servers as $server => $config) {
            $router->group($this->groupAttributes($config), function () use ($router, $server): void {
                $this->registerServer($router, $server);
            });
        }
    }

    /**
     * Registers one server's routes at the current router position (inheriting any
     * surrounding group) — the primitive the `Route::jsonApi()` macro calls for manual
     * placement.
     */
    public function registerServer(Router $router, string $server = ServerRegistry::DEFAULT_SERVER): void
    {
        $namePrefix = $server === ServerRegistry::DEFAULT_SERVER
            ? 'jsonapi.'
            : \sprintf('jsonapi.%s.', $server);

        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            $this->addResourceRoutes($router, $server, $namePrefix, $descriptor);
        }
    }

    private function addResourceRoutes(Router $router, string $server, string $namePrefix, ResourceDescriptor $descriptor): void
    {
        $type = $descriptor->type;
        $collectionPath = '/' . $descriptor->uriType;
        $resourcePath = $collectionPath . '/{id}';

        if ($descriptor->exposes(Operation::FetchCollection)) {
            $this->configure($router->get($collectionPath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.index');
        }

        if ($descriptor->exposes(Operation::Create)) {
            $this->configure($router->post($collectionPath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.create');
        }

        if ($descriptor->exposes(Operation::FetchOne)) {
            $this->configure($router->get($resourcePath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.show');
        }

        if ($descriptor->exposes(Operation::Update)) {
            $this->configure($router->patch($resourcePath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.update');
        }

        if ($descriptor->exposes(Operation::Delete)) {
            $this->configure($router->delete($resourcePath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.delete');
        }

        // The two parametric relation GET routes, emitted for any fetchable-by-id resource
        // (the relationship endpoints hang off `/{id}`). They stay parametric (`{relationship}`);
        // the handler enforces per-relation exposure + existence as a `404`
        // (RelationshipNotExists), so a relation-less type simply 404s every relationship
        // name. The relationship-linkage route is registered FIRST (its literal
        // `relationships` segment) so the related route's `{relationship}` never captures it.
        // Relationship MUTATION routes (PATCH/POST/DELETE on `/relationships/{rel}`) are 3b.
        if ($descriptor->exposes(Operation::FetchOne)) {
            $this->configure($router->get($resourcePath . '/relationships/{relationship}', JsonApiController::class), $server, $type)
                ->defaults(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true)
                ->name($namePrefix . $type . '.relationship.show');

            $this->configure($router->get($resourcePath . '/{relationship}', JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.related.show');
        }
    }

    /**
     * Stamps the JSON:API route defaults the target resolver + exception renderer read.
     */
    private function configure(Route $route, string $server, string $type): Route
    {
        return $route
            ->defaults(TargetResolver::TYPE_ATTRIBUTE, $type)
            ->defaults(TargetResolver::SERVER_ATTRIBUTE, $server)
            ->defaults(JsonApiExceptionRenderer::ROUTE_MARKER, true);
    }

    /**
     * The route-group attributes for a server, dropping empty prefix/middleware/domain
     * so a bare server config produces an unqualified group.
     *
     * @param array{prefix?: string, middleware?: list<string>|string, domain?: string|null} $config
     *
     * @return array<string, mixed>
     */
    private function groupAttributes(array $config): array
    {
        $attributes = [];

        $prefix = $config['prefix'] ?? '';
        if (\is_string($prefix) && $prefix !== '') {
            $attributes['prefix'] = $prefix;
        }

        $middleware = $config['middleware'] ?? [];
        if ($middleware !== [] && $middleware !== '') {
            $attributes['middleware'] = $middleware;
        }

        $domain = $config['domain'] ?? null;
        if (\is_string($domain) && $domain !== '') {
            $attributes['domain'] = $domain;
        }

        return $attributes;
    }
}
