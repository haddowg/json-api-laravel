<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * `JsonApiRoute::of()` on each kind of route the package registers, matched through the
 * real router: collection, resource, related, relationship, both action scopes and
 * atomic, plus a plain app route.
 *
 * @internal
 */
#[CoversNothing]
final class JsonApiRouteOfTest extends Orchestra
{
    public function test_collection_route(): void
    {
        self::assertEquals(new JsonApiRoute('default', 'albums'), $this->of('GET', '/api/albums'));
        self::assertEquals(new JsonApiRoute('default', 'albums'), $this->of('POST', '/api/albums'));
    }

    public function test_resource_route(): void
    {
        $route = $this->of('PATCH', '/api/albums/1');

        self::assertEquals(new JsonApiRoute('default', 'albums', '1'), $route);
        self::assertEquals(new Target('albums', '1'), $route?->target());
    }

    public function test_related_route(): void
    {
        $route = $this->of('GET', '/api/albums/1/artist');

        self::assertEquals(new JsonApiRoute('default', 'albums', '1', 'artist'), $route);
        self::assertEquals(new Target('albums', '1', 'artist', false), $route?->target());
    }

    public function test_relationship_route(): void
    {
        $route = $this->of('PATCH', '/api/albums/1/relationships/artist');

        self::assertEquals(new JsonApiRoute('default', 'albums', '1', 'artist', isRelationshipEndpoint: true), $route);
        self::assertEquals(new Target('albums', '1', 'artist', true), $route?->target());
    }

    public function test_resource_scope_action_route(): void
    {
        $route = $this->of('POST', '/api/albums/1/-actions/publish');

        self::assertEquals(new JsonApiRoute('default', 'albums', '1', action: 'publish', actionScope: ActionScope::Resource), $route);
        self::assertEquals(new Target('albums', '1'), $route?->target());
    }

    public function test_collection_scope_action_route(): void
    {
        $route = $this->of('POST', '/api/albums/-actions/purge');

        self::assertEquals(new JsonApiRoute('default', 'albums', action: 'purge', actionScope: ActionScope::Collection), $route);
        self::assertEquals(new Target('albums'), $route?->target());
    }

    public function test_atomic_route(): void
    {
        $route = $this->of('POST', '/api/operations');

        self::assertEquals(new JsonApiRoute('default', isAtomic: true), $route);
        self::assertNull($route?->target());
    }

    public function test_a_plain_route_is_null(): void
    {
        self::assertNull($this->of('GET', '/plain'));
    }

    public function test_an_unrouted_request_is_null(): void
    {
        self::assertNull(JsonApiRoute::of(Request::create('/api/albums')));
        self::assertNull(Request::create('/api/albums')->jsonApi());
    }

    public function test_the_macro_delegates_to_of(): void
    {
        $request = $this->routed('GET', '/api/albums/1');

        self::assertEquals(JsonApiRoute::of($request), $request->jsonApi());
        self::assertSame('albums', $request->jsonApi()?->type);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            SurfaceInMemoryServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/plain', static fn(): string => 'ok');
    }

    private function of(string $method, string $uri): ?JsonApiRoute
    {
        return JsonApiRoute::of($this->routed($method, $uri));
    }

    private function routed(string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $route = $router->getRoutes()->match($request);
        $request->setRouteResolver(static fn() => $route);

        return $request;
    }
}
