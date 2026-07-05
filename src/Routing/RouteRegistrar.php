<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Routing;

use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\Http\ResponseHeadersMiddleware;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Emits the JSON:API endpoint routes from the discovered {@see ResourceDescriptor}s +
 * {@see ActionDescriptor}s, operation-gated exactly as the Symfony bundle's route loader:
 * one literal route per operation a type declares, so an unexposed verb is unrouted
 * (Laravel 404s/405s natively) rather than reaching a handler that refuses.
 *
 *  - {@see Operation::FetchCollection} → `GET /{uriType}` — `{type}.index`
 *  - {@see Operation::Create}          → `POST /{uriType}` — `{type}.create`
 *  - {@see Operation::FetchOne}        → `GET /{uriType}/{id}` — `{type}.show`
 *  - {@see Operation::Update}          → `PATCH /{uriType}/{id}` — `{type}.update`
 *  - {@see Operation::Delete}          → `DELETE /{uriType}/{id}` — `{type}.delete`
 *
 * A fetchable-by-id type additionally gets the two parametric relation GET routes (linkage
 * first so its literal `relationships` segment is never captured as a `{relationship}`), and
 * a writable type the three relationship-mutation routes.
 *
 * **Custom actions + Atomic Operations (Phase 4).** Per server, the opt-in Atomic Operations
 * endpoint (`POST {atomic.path}`) and the discovered custom-action routes
 * (`POST /{uriType}[/{id}]/-actions/{path}`, the author's declared methods) are emitted
 * FIRST, before the generic routes, so their literal paths win their own verbs cleanly and
 * the reserved `-actions` segment is never captured as an `{id}` (every `{id}`-carrying route
 * excludes the literal `-actions` from its id requirement, {@see idRequirement()}). A
 * configuration that would silently shadow a type's collection `POST` Create with the atomic
 * path is a build-time {@see \LogicException} ({@see guardAtomicPathCollision()}).
 *
 * **Id route pattern (PLAN decision 4).** When a type's {@see \haddowg\JsonApi\Resource\Field\Id} field declares a route
 * pattern (`uuid()`/`ulid()`/`numeric()`/`pattern()`/`matchAs()`, core ADR 0038), that
 * pattern is composed INTO the `{id}` requirement — `(?!-actions(?:/|$))(?:<pattern>)` — so
 * a malformed id 404s at routing exactly as the projected OpenAPI document (which advertises
 * the same `idPattern` anchored `^(?:…)$`) declares. The pattern is read lazily from the
 * resource's own `Id` field (constructed via the container, the same lazy resolver core
 * uses), memoized per class; a type with no declared pattern keeps Laravel's single-segment
 * default. Because the composed requirement is baked into each emitted route's `where`,
 * `route:cache` serializes it and registration stays a pure function of the descriptor list.
 *
 * Route names match the bundle: `jsonapi.{type}.{action}` for the default server,
 * `jsonapi.{server}.{type}.{action}` for a named server.
 */
final class RouteRegistrar
{
    /**
     * Memoized composed `{id}` requirements, keyed by resource class-string (`''` for a
     * type with no discovered resource) — so each resource is constructed at most once to
     * read its declared Id route pattern.
     *
     * @var array<string, string>
     */
    private array $idRequirementCache = [];

    /**
     * @param array<string, array{prefix?: string, middleware?: list<string>|string, domain?: string|null}> $servers          the per-server route config
     * @param (\Closure(class-string): ?string)|null                                                         $idPatternResolver resolves a resource class to its {@see Id} field's declared route pattern (null when unconstrained); null disables the lookup (the single-segment default)
     */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly array $servers,
        private readonly bool $atomicEnabled = false,
        private readonly string $atomicPath = '/operations',
        private readonly ?\Closure $idPatternResolver = null,
    ) {}

    /**
     * Registers every configured server's routes, each within its own prefix / middleware /
     * domain group — the automatic path the service provider drives.
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

        // Refuse a configuration collision up front, then emit the atomic + action routes
        // FIRST (literal paths / declared methods) so they win over the generic routes.
        $this->guardAtomicPathCollision($server);
        $this->addAtomicRoute($router, $server, $namePrefix);
        $this->addActionRoutes($router, $server, $namePrefix);

        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            $this->addResourceRoutes($router, $server, $namePrefix, $descriptor);
        }

        // Standalone-serializer types (no resource; PLAN decision 3, bundle ADR 0024) are
        // emitted after the resources, matching the bundle's descriptor order (resources,
        // then standalone types) so the projected OpenAPI paths stay byte-compatible.
        foreach ($this->discovery->serializersFor($server) as $descriptor) {
            $this->addSerializerRoutes($router, $server, $namePrefix, $descriptor);
        }
    }

    /**
     * Emits the operation-gated read routes for a standalone-serializer type — only the
     * two fetch verbs its allow-list opens (a serialize-only type with an empty allow-list
     * gets none). A resource-less type declares no relations and no writes, so it never
     * gets the relation or mutation routes an `AbstractResource` does; its `{id}` uses the
     * default single-segment requirement (no resource to read an Id route pattern from).
     */
    private function addSerializerRoutes(Router $router, string $server, string $namePrefix, SerializerDescriptor $descriptor): void
    {
        $type = $descriptor->type;
        $collectionPath = '/' . $descriptor->uriType;
        $resourcePath = $collectionPath . '/{id}';
        $idRequirement = $this->idRequirementFor(null);

        if ($descriptor->exposes(Operation::FetchCollection)) {
            $this->configure($router->get($collectionPath, JsonApiController::class), $server, $type)
                ->name($namePrefix . $type . '.index');
        }

        if ($descriptor->exposes(Operation::FetchOne)) {
            $this->configure($router->get($resourcePath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->name($namePrefix . $type . '.show');
        }
    }

    private function addResourceRoutes(Router $router, string $server, string $namePrefix, ResourceDescriptor $descriptor): void
    {
        $type = $descriptor->type;
        $collectionPath = '/' . $descriptor->uriType;
        $resourcePath = $collectionPath . '/{id}';

        // The `{id}` requirement for THIS type: the reserved-`-actions` lookahead composed in
        // front of the type's own declared Id route pattern (uuid()/numeric()/matchAs()/… — or
        // the single-segment default), so a malformed id 404s exactly as the projected document
        // declares.
        $idRequirement = $this->idRequirementFor($descriptor->class);

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
                ->where('id', $idRequirement)
                ->name($namePrefix . $type . '.show');
        }

        if ($descriptor->exposes(Operation::Update)) {
            $this->configure($router->patch($resourcePath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->name($namePrefix . $type . '.update');
        }

        if ($descriptor->exposes(Operation::Delete)) {
            $this->configure($router->delete($resourcePath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->name($namePrefix . $type . '.delete');
        }

        // The two parametric relation GET routes, emitted for any fetchable-by-id resource.
        // The relationship-linkage route is registered FIRST (its literal `relationships`
        // segment) so the related route's `{relationship}` never captures it.
        if ($descriptor->exposes(Operation::FetchOne)) {
            $this->configure($router->get($resourcePath . '/relationships/{relationship}', JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->defaults(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true)
                ->name($namePrefix . $type . '.relationship.show');

            $this->configure($router->get($resourcePath . '/{relationship}', JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->name($namePrefix . $type . '.related.show');
        }

        // The three parametric relationship-MUTATION routes, emitted for any writable
        // resource; the handler enforces per-relation exposure (404) + the mutability flags (403).
        if ($descriptor->exposes(Operation::Update)) {
            $relationshipPath = $resourcePath . '/relationships/{relationship}';

            $this->configure($router->patch($relationshipPath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->defaults(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true)
                ->name($namePrefix . $type . '.relationship.update');

            $this->configure($router->post($relationshipPath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->defaults(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true)
                ->name($namePrefix . $type . '.relationship.add');

            $this->configure($router->delete($relationshipPath, JsonApiController::class), $server, $type)
                ->where('id', $idRequirement)
                ->defaults(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true)
                ->name($namePrefix . $type . '.relationship.remove');
        }
    }

    /**
     * The reserved path segment custom actions hang off. It is a literal in every action
     * path and must never be captured as a resource `{id}`, so every `{id}`-carrying route's
     * id pattern excludes it via a leading negative lookahead anchored to the segment
     * boundary (`(?:/|$)` covers a mid-path `-actions/` and a trailing `-actions`).
     */
    private const string RESERVED_ACTIONS_SEGMENT = '-actions';

    /**
     * The single-segment `{id}` body a type with no declared Id route pattern uses —
     * Laravel's implicit placeholder regex (`[^/]+`), so an id still cannot span a `/`.
     */
    private const string DEFAULT_ID_BODY = '[^/]+';

    /**
     * The composed `{id}` requirement for the resource `$class`: the reserved-`-actions`
     * segment lookahead (so a collection-scope action `/{uriType}/-actions/{name}` is never
     * shadowed by the generic related route `/{uriType}/{id}/{relationship}` with `{id}` =
     * `-actions`) in front of the type's own declared Id route pattern (or the single-segment
     * default). Memoized per class so each resource is constructed at most once. The lookahead
     * is anchored to the segment boundary (`(?:/|$)` covers a mid-path `-actions/` and a
     * trailing `-actions`).
     *
     * @param class-string|null $class
     */
    private function idRequirementFor(?string $class): string
    {
        $key = $class ?? '';

        return $this->idRequirementCache[$key] ??= $this->composeIdRequirement($class);
    }

    /**
     * @param class-string|null $class
     */
    private function composeIdRequirement(?string $class): string
    {
        $pattern = $class !== null && $this->idPatternResolver !== null
            ? ($this->idPatternResolver)($class)
            : null;

        $body = $pattern !== null && $pattern !== '' ? '(?:' . $pattern . ')' : self::DEFAULT_ID_BODY;

        return '(?!' . self::RESERVED_ACTIONS_SEGMENT . '(?:/|$))' . $body;
    }

    /**
     * Emits the per-server Atomic Operations endpoint (`POST {path}`, opt-in). The route
     * carries the standard JSON:API defaults plus the atomic marker and — deliberately — NO
     * `_jsonapi_type`, so the {@see TargetResolver} returns null and the controller branches
     * on the marker to negotiate the atomic ext, parse `atomic:operations`, and run the batch.
     */
    private function addAtomicRoute(Router $router, string $server, string $namePrefix): void
    {
        if (!$this->atomicEnabled) {
            return;
        }

        $router->post($this->atomicPath, JsonApiController::class)
            ->defaults(TargetResolver::SERVER_ATTRIBUTE, $server)
            ->defaults(JsonApiExceptionRenderer::ROUTE_MARKER, true)
            ->defaults(JsonApiController::ATOMIC_ATTRIBUTE, true)
            ->name($namePrefix . 'atomic_operations');
    }

    /**
     * Refuses, at route-registration time, an Atomic Operations path that would silently
     * shadow a resource's collection-`POST` Create on the same server (both are `POST {path}`,
     * and the atomic route is emitted first). A no-op when the extension is disabled.
     */
    private function guardAtomicPathCollision(string $server): void
    {
        if (!$this->atomicEnabled) {
            return;
        }

        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            if ('/' . $descriptor->uriType !== $this->atomicPath) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'The Atomic Operations path "%s" collides with the collection path of the JSON:API type "%s" '
                . '(uriType "%s") on server "%s": both are served at "POST %s", so the type\'s Create would be '
                . 'silently shadowed. Change jsonapi.atomic_operations.path or the type\'s uriType so the two differ.',
                $this->atomicPath,
                $descriptor->type,
                $descriptor->uriType,
                $server,
                $this->atomicPath,
            ));
        }

        // Standalone-serializer types need no arm here: {@see addSerializerRoutes} emits GET
        // only (FetchCollection/FetchOne), so a standalone type never opens a collection POST
        // that the atomic path could shadow — a Create in its allow-list is unrouteable, not a
        // collision.
    }

    /**
     * Emits the custom-action routes for `$server`, each under the reserved `-actions`
     * segment with the action's author-declared HTTP methods. The single `{action}` segment
     * is emitted as a **literal** (the action's own name), so two actions of the same
     * (type, scope) but different methods never collapse onto one parametric path:
     *  - resource scope: `/{uriType}/{id}/-actions/{name}` (the `{id}` is resolved to an
     *    entity before the handler runs, and carries the reserved-segment id exclusion);
     *  - collection scope: `/{uriType}/-actions/{name}` (no id).
     *
     * Each route carries `_jsonapi_type`, `_jsonapi_server`, the route marker, the action
     * name marker and the scope-name default the controller branches on.
     */
    private function addActionRoutes(Router $router, string $server, string $namePrefix): void
    {
        foreach ($this->discovery->actionsFor($server) as $action) {
            $resourceScope = $action->scope === ActionScope::Resource;

            $uriType = $this->uriTypeFor($server, $action->type);
            $path = $resourceScope
                ? \sprintf('/%s/{id}/%s/%s', $uriType, self::RESERVED_ACTIONS_SEGMENT, $action->path)
                : \sprintf('/%s/%s/%s', $uriType, self::RESERVED_ACTIONS_SEGMENT, $action->path);

            $route = $router->match($action->methods, $path, JsonApiController::class)
                ->defaults(TargetResolver::TYPE_ATTRIBUTE, $action->type)
                ->defaults(TargetResolver::SERVER_ATTRIBUTE, $server)
                ->defaults(JsonApiExceptionRenderer::ROUTE_MARKER, true)
                ->defaults(JsonApiController::ACTION_ATTRIBUTE, $action->path)
                ->defaults(JsonApiController::ACTION_SCOPE_ATTRIBUTE, $action->scope->name)
                ->middleware(ResponseHeadersMiddleware::class)
                ->name(self::actionRouteName($action));

            if ($resourceScope) {
                // The resource-scope action's `{id}` carries the mount type's own declared Id
                // route pattern, so a malformed id 404s consistently with the CRUD routes.
                $route->where('id', $this->idRequirementFor($this->classFor($server, $action->type)));
            }
        }
    }

    /**
     * The stable route name for a custom action: `{namePrefix}{name}` when the attribute
     * declared a `name` override, else `{namePrefix}{type}.action.{scope}.{path}` (mirroring
     * the bundle). Shared with {@see \haddowg\JsonApiLaravel\Action\ActionLinkContributor} so
     * the `asLink` URL resolves the same route.
     */
    public static function actionRouteName(ActionDescriptor $descriptor): string
    {
        $namePrefix = $descriptor->server === ServerRegistry::DEFAULT_SERVER
            ? 'jsonapi.'
            : \sprintf('jsonapi.%s.', $descriptor->server);

        if ($descriptor->name !== null && $descriptor->name !== '') {
            return $namePrefix . $descriptor->name;
        }

        return \sprintf('%s%s.action.%s.%s', $namePrefix, $descriptor->type, \strtolower($descriptor->scope->name), $descriptor->path);
    }

    /**
     * The URI path segment for an action's mount type, read off the type's discovered
     * resource descriptor (its `uriType`), falling back to the type itself when the mount is
     * a bare type with no scanned resource on this server.
     */
    private function uriTypeFor(string $server, string $type): string
    {
        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            if ($descriptor->type === $type) {
                return $descriptor->uriType;
            }
        }

        return $type;
    }

    /**
     * The resource class-string backing an action's mount type on `$server`, or `null` when
     * the mount is a bare type with no scanned resource (then the `{id}` uses the default
     * single-segment requirement).
     *
     * @return class-string|null
     */
    private function classFor(string $server, string $type): ?string
    {
        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            if ($descriptor->type === $type) {
                return $descriptor->class;
            }
        }

        return null;
    }

    /**
     * Stamps the JSON:API route defaults the target resolver + exception renderer read,
     * and pushes the {@see ResponseHeadersMiddleware} onto the route so the type's
     * declarative cache + deprecation/sunset headers are emitted post-controller
     * (the Laravel twin of the bundle's `kernel.response` listener). Middleware
     * referenced by class-string is `route:cache`-safe.
     */
    private function configure(Route $route, string $server, string $type): Route
    {
        return $route
            ->defaults(TargetResolver::TYPE_ATTRIBUTE, $type)
            ->defaults(TargetResolver::SERVER_ATTRIBUTE, $server)
            ->defaults(JsonApiExceptionRenderer::ROUTE_MARKER, true)
            ->middleware(ResponseHeadersMiddleware::class);
    }

    /**
     * The route-group attributes for a server, dropping empty prefix/middleware/domain so a
     * bare server config produces an unqualified group.
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
