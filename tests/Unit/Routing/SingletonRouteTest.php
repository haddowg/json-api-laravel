<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Routing;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\StandaloneSettingsHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\StandaloneSettingsSerializer;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workbench\App\Singleton\ArchiveInbox;
use Workbench\App\Singleton\InboxItemResource;
use Workbench\App\Singleton\InboxLabelResource;
use Workbench\App\Singleton\InboxResource;

/**
 * A singleton type is routed at `/{uriType}` with no `{id}`: its fetch and update, its
 * relationship and related endpoints and its actions, and nothing else. Each route carries
 * the singleton marker, so its target is a singleton target.
 *
 * @internal
 */
#[CoversClass(RouteRegistrar::class)]
#[CoversClass(TargetResolver::class)]
#[CoversClass(JsonApiRoute::class)]
final class SingletonRouteTest extends TestCase
{
    public function test_a_singleton_resource_is_routed_at_its_types_path(): void
    {
        $routes = $this->registerRoutes([InboxResource::class, InboxItemResource::class, InboxLabelResource::class, ArchiveInbox::class]);

        self::assertSame([
            'POST inbox/-actions/archive',
            'GET|HEAD inbox',
            'PATCH inbox',
            'GET|HEAD inbox/relationships/{relationship}',
            'GET|HEAD inbox/{relationship}',
            'PATCH inbox/relationships/{relationship}',
            'POST inbox/relationships/{relationship}',
            'DELETE inbox/relationships/{relationship}',
        ], $this->singletonRoutes($routes));

        foreach (['index', 'create', 'delete'] as $action) {
            self::assertNull($routes->getRoutes()->getByName('jsonapi.inboxes.' . $action));
        }
        self::assertSame('inbox', $routes->getRoutes()->getByName('jsonapi.inboxes.show')?->uri());
        self::assertSame('inbox/-actions/archive', $routes->getRoutes()->getByName('jsonapi.inboxes.action.resource.archive')?->uri());
    }

    public function test_a_standalone_singleton_serializer_is_routed_at_its_types_path(): void
    {
        $alone = $this->registerRoutes([StandaloneSettingsSerializer::class]);
        self::assertSame(['GET|HEAD standaloneSettings'], $this->singletonRoutes($alone));

        $hydrated = $this->registerRoutes([StandaloneSettingsSerializer::class, StandaloneSettingsHydrator::class]);
        self::assertSame(['GET|HEAD standaloneSettings', 'PATCH standaloneSettings'], $this->singletonRoutes($hydrated));
    }

    public function test_a_singleton_route_resolves_to_a_singleton_target(): void
    {
        $routes = $this->registerRoutes([InboxResource::class, InboxItemResource::class, InboxLabelResource::class, ArchiveInbox::class]);

        $request = Request::create('/inbox/relationships/items');
        $route = $routes->getRoutes()->match($request);
        $request->setRouteResolver(static fn() => $route);

        $target = (new TargetResolver())->resolveFromRequest($request);
        self::assertEquals(Target::singleton('inboxes', null, 'items', true), $target);

        $endpoint = JsonApiRoute::of($request);
        self::assertNotNull($endpoint);
        self::assertTrue($endpoint->singleton);
        self::assertEquals($target, $endpoint->target());
    }

    /**
     * @param list<class-string> $classes
     */
    private function registerRoutes(array $classes): Router
    {
        $discovery = new Discovery(new DiscoveryScanner(), [], $classes);
        $router = new Router(new Dispatcher());

        (new RouteRegistrar($discovery, ['default' => []]))->registerConfiguredServers($router);
        $router->getRoutes()->refreshNameLookups();

        return $router;
    }

    /**
     * @return list<string>
     */
    private function singletonRoutes(Router $router): array
    {
        $routes = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (($route->defaults[TargetResolver::SINGLETON_ATTRIBUTE] ?? false) === true) {
                $routes[] = \implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        return $routes;
    }
}
