<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Http\JsonApiController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Asserts the auto-registered route table: one operation-gated route per read-only
 * type, named exactly as the Symfony bundle (`jsonapi.{type}.{action}`), under the
 * configured `api` prefix, all pointing at the single {@see JsonApiController}. Because
 * the workbench types are read-only, only the two read routes exist per type — the
 * write routes are gated out.
 *
 * @internal
 */
#[CoversNothing]
final class RouteRegistrationTest extends TestCase
{
    public function test_the_expected_read_routes_are_registered(): void
    {
        $expected = [
            'jsonapi.artists.index' => ['GET', 'api/artists'],
            'jsonapi.artists.show' => ['GET', 'api/artists/{id}'],
            'jsonapi.albums.index' => ['GET', 'api/albums'],
            'jsonapi.albums.show' => ['GET', 'api/albums/{id}'],
            'jsonapi.genres.index' => ['GET', 'api/genres'],
            'jsonapi.genres.show' => ['GET', 'api/genres/{id}'],
        ];

        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $routes = $router->getRoutes();

        foreach ($expected as $name => [$method, $uri]) {
            $route = $routes->getByName($name);
            self::assertInstanceOf(Route::class, $route, "Route {$name} should be registered.");
            self::assertContains($method, $route->methods());
            self::assertSame($uri, $route->uri());
            self::assertSame(JsonApiController::class, $route->getActionName());
        }
    }

    public function test_write_routes_are_not_registered_for_read_only_types(): void
    {
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $routes = $router->getRoutes();

        self::assertNull($routes->getByName('jsonapi.artists.create'));
        self::assertNull($routes->getByName('jsonapi.artists.update'));
        self::assertNull($routes->getByName('jsonapi.artists.delete'));
    }

    public function test_the_route_macro_is_registered_for_manual_placement(): void
    {
        self::assertTrue(RouteFacade::hasMacro('jsonApi'));
    }
}
