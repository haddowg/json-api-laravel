<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Routing;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The JSON:API endpoint a request was routed to.
 *
 * Every route {@see RouteRegistrar} emits (resource, relationship, custom action and
 * atomic) carries the {@see self::MARKER} default, and nothing else does. The facts come
 * from the matched route, so {@see self::matches()} is `false` and {@see self::of()} is
 * `null` until Laravel's router has matched one: route middleware sees them, global
 * middleware does not. The service provider also registers them as the
 * `$request->isJsonApi()` and `$request->jsonApi()` macros.
 *
 * It carries no Server and loads no model. {@see ServerRegistry::forRequest()} gives the
 * effective Server for the request; hooks and handlers receive the loaded model.
 */
final readonly class JsonApiRoute
{
    /**
     * The route default that marks a JSON:API endpoint.
     */
    public const string MARKER = '_jsonapi';

    /**
     * The name of the {@see Request} macro that delegates to {@see self::matches()}.
     */
    public const string MACRO = 'isJsonApi';

    /**
     * The name of the {@see Request} macro that delegates to {@see self::of()}.
     */
    public const string ROUTE_MACRO = 'jsonApi';

    /**
     * @param string           $server                 the server name, `default` for the implicit server
     * @param string|null      $type                   the JSON:API type; null on the atomic route
     * @param string|null      $id                     the `{id}` route parameter; null on collection routes
     * @param string|null      $relationship           the `{relationship}` route parameter
     * @param bool             $isRelationshipEndpoint true on `/relationships/{relationship}`
     * @param string|null      $action                 a custom action's path segment
     * @param ActionScope|null $actionScope            a custom action's scope
     */
    public function __construct(
        public string $server,
        public ?string $type = null,
        public ?string $id = null,
        public ?string $relationship = null,
        public bool $isRelationshipEndpoint = false,
        public ?string $action = null,
        public ?ActionScope $actionScope = null,
        public bool $isAtomic = false,
    ) {}

    /**
     * Whether the request's matched route is a JSON:API endpoint. `false` before
     * routing, and for any route the package didn't emit that doesn't set the marker
     * itself.
     */
    public static function matches(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && ($route->defaults[self::MARKER] ?? false) === true;
    }

    /**
     * The JSON:API endpoint the request was routed to, or `null` when {@see self::matches()}
     * is false.
     */
    public static function of(Request $request): ?self
    {
        $route = $request->route();
        if (!$route instanceof Route || !self::matches($request)) {
            return null;
        }

        $defaults = $route->defaults;
        // The same reads the controller dispatches on, so target() is the dispatched Target.
        $target = (new TargetResolver())->resolveFromRequest($request);

        $server = $defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $action = $defaults[JsonApiController::ACTION_ATTRIBUTE] ?? null;
        $action = \is_string($action) && $action !== '' ? $action : null;

        return new self(
            server: \is_string($server) && $server !== '' ? $server : ServerRegistry::DEFAULT_SERVER,
            type: $target?->type,
            id: $target?->id,
            relationship: $target?->relationship,
            isRelationshipEndpoint: $target !== null && $target->isRelationshipEndpoint,
            action: $action,
            actionScope: $action !== null ? self::actionScopeOf($defaults[JsonApiController::ACTION_SCOPE_ATTRIBUTE] ?? null) : null,
            isAtomic: ($defaults[JsonApiController::ATOMIC_ATTRIBUTE] ?? null) === true,
        );
    }

    /**
     * Core's Target for this endpoint, or `null` when there is no type (the atomic route).
     */
    public function target(): ?Target
    {
        if ($this->type === null) {
            return null;
        }

        return new Target($this->type, $this->id, $this->relationship, $this->isRelationshipEndpoint);
    }

    /**
     * Registers `$request->isJsonApi()` and `$request->jsonApi()`. Idempotent.
     */
    public static function registerMacro(): void
    {
        Request::macro(self::MACRO, function (): bool {
            /** @var Request $this */
            return JsonApiRoute::matches($this);
        });

        Request::macro(self::ROUTE_MACRO, function (): ?JsonApiRoute {
            /** @var Request $this */
            return JsonApiRoute::of($this);
        });
    }

    private static function actionScopeOf(mixed $name): ?ActionScope
    {
        foreach (ActionScope::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
