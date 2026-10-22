<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two servers under different prefixes, both with an empty `base_uri`, each link under
 * their own prefix (ADR 0028). `gizmos` is served on both.
 *
 * @internal
 */
#[CoversNothing]
final class PrefixedMultiServerLinksTest extends Orchestra
{
    use InteractsWithJsonApi;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            LifecycleServiceProvider::class,
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
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    #[Test]
    #[Group('links')]
    public function eachServerLinksUnderItsOwnPrefix(): void
    {
        $this->jsonApi()->get('/api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self', 'http://localhost/api/gizmos/1')
            ->assertJsonPath('data.links.self', 'http://localhost/api/gizmos/1');

        $this->jsonApi()->get('/admin-api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self', 'http://localhost/admin-api/gizmos/1')
            ->assertJsonPath('data.links.self', 'http://localhost/admin-api/gizmos/1');
    }

    #[Test]
    #[Group('links')]
    public function theRoutePrefixSurvivesRouteCaching(): void
    {
        $router = $this->resolveRouter();
        $routes = $router->getRoutes();
        \assert($routes instanceof RouteCollection);
        $router->setCompiledRoutes($routes->compile());

        $this->jsonApi()->get('/admin-api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('data.links.self', 'http://localhost/admin-api/gizmos/1');
    }

    private function resolveRouter(): Router
    {
        $router = $this->app?->make(Router::class);
        \assert($router instanceof Router);

        return $router;
    }
}
