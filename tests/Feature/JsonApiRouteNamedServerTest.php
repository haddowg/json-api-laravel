<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * `JsonApiRoute::of()` names the server a route belongs to: `default` (genres) under
 * `api` and `secure` (albums) under `secure-api`.
 *
 * @internal
 */
#[CoversNothing]
final class JsonApiRouteNamedServerTest extends Orchestra
{
    public function test_the_default_server(): void
    {
        self::assertEquals(new JsonApiRoute('default', 'genres'), $this->of('/api/genres'));
    }

    public function test_a_named_server(): void
    {
        self::assertEquals(new JsonApiRoute('secure', 'albums', '1'), $this->of('/secure-api/albums/1'));
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
            SecurityInMemoryServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    private function of(string $uri): ?JsonApiRoute
    {
        $request = Request::create($uri);
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $route = $router->getRoutes()->match($request);
        $request->setRouteResolver(static fn() => $route);

        return JsonApiRoute::of($request);
    }
}
