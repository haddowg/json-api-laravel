<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\RouteCheck\RecordsJsonApiRoute;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * `$request->isJsonApi()` and `$request->jsonApi()` as application middleware sees them: true on a JSON:API route
 * and false on a plain app route once the router has matched, and false in global
 * middleware, which runs before routing.
 *
 * @internal
 */
#[CoversNothing]
final class JsonApiRouteMacroTest extends Orchestra
{
    private const string PROBE = RecordsJsonApiRoute::class . ':route';

    protected function setUp(): void
    {
        parent::setUp();
        RecordsJsonApiRoute::$seen = [];
    }

    public function test_route_middleware_sees_true_on_a_json_api_route(): void
    {
        $this->get('/api/albums', ['Accept' => 'application/vnd.api+json'])->assertOk();

        self::assertSame([['label' => 'route', 'path' => 'api/albums', 'isJsonApi' => true, 'type' => 'albums']], RecordsJsonApiRoute::$seen);
    }

    public function test_route_middleware_sees_false_on_a_plain_app_route(): void
    {
        $this->get('/plain')->assertOk();

        self::assertSame([['label' => 'route', 'path' => 'plain', 'isJsonApi' => false, 'type' => null]], RecordsJsonApiRoute::$seen);
    }

    public function test_global_middleware_runs_before_routing_and_sees_false(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);
        $kernel->pushMiddleware(RecordsJsonApiRoute::class);

        $this->get('/api/albums', ['Accept' => 'application/vnd.api+json'])->assertOk();

        self::assertSame([
            ['label' => 'global', 'path' => 'api/albums', 'isJsonApi' => false, 'type' => null],
            ['label' => 'route', 'path' => 'api/albums', 'isJsonApi' => true, 'type' => 'albums'],
        ], RecordsJsonApiRoute::$seen);
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
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('jsonapi.discovery.paths', [\dirname(__DIR__) . '/Fixtures/GettingStarted/JsonApi']);
        $config->set('jsonapi.eloquent.model_namespace', 'haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\Models');
        $config->set('jsonapi.servers.default.middleware', [self::PROBE]);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/plain', static fn(): string => 'ok')->middleware(self::PROBE);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('albums', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamp('released_at')->nullable();
            $table->boolean('explicit')->default(false);
            $table->timestamps();
        });
    }
}
