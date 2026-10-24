<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Http\OpenApiController;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\DocsRoute\IgnoresRoutesServiceProvider;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\DocsRoute\WarningLogger;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * How `describedby` finds the document when the app routes the OpenAPI controller itself
 * rather than letting the package register its document routes, and the warning when
 * nothing serves it.
 *
 * The app places the JSON:API routes itself (`default` under `/api`, `admin` under
 * `/admin-api`). With `custom` docs routes it routes the controller as `/api/docs.json`
 * and, for `admin`, `/api/v5/docs.json` (in combined mode, `/api/combined/docs.json`
 * alone); with `none` nothing serves the documents.
 *
 * @internal
 */
#[CoversNothing]
final class OpenApiDocumentRouteLookupTest extends Orchestra
{
    use InteractsWithJsonApi;

    private const string GIZMO_POINTER = '#/paths/~1gizmos~1%7Bid%7D/get';

    private string $cacheDir = '';

    private string $docsRoutes = 'custom';

    private bool $exposed = true;

    private bool $combined = false;

    private bool $mountsDefault = true;

    private WarningLogger $logger;

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
            IgnoresRoutesServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-docs-route-' . \uniqid();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('jsonapi.openapi.expose_in_prod', $this->exposed);
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);
        // `gizmos` is on both servers, which a combined document can't hold, so combined
        // mode runs the one server.
        $config->set('jsonapi.servers', $this->combined
            ? ['default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null]]
            : [
                'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
                'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
            ]);

        $this->logger = new WarningLogger();
        $app->instance(LoggerInterface::class, $this->logger);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function combined($app): void
    {
        $this->combined = true;

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.multi_server', 'combined');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function documentUrl($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.document_url', 'https://docs.example.test/{server}.json');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function noDocsRoutes($app): void
    {
        $this->docsRoutes = 'none';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function hiddenDocs($app): void
    {
        $this->docsRoutes = 'none';
        $this->exposed = false;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function onlyAdminMounted($app): void
    {
        $this->docsRoutes = 'none';
        $this->mountsDefault = false;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function combinedWithNothingMounted($app): void
    {
        $this->combined($app);
        $this->docsRoutes = 'none';
        $this->mountsDefault = false;
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        if ($this->mountsDefault) {
            $router->prefix('api')->group(static fn(Router $router) => $router->jsonApi());
        }
        if (!$this->combined) {
            $router->prefix('admin-api')->group(static fn(Router $router) => $router->jsonApi('admin'));
        }

        if ($this->docsRoutes !== 'custom') {
            return;
        }

        if ($this->combined) {
            // A server default doesn't narrow the combined document's route.
            $router->get('/api/combined/docs.json', [OpenApiController::class, '__invoke'])
                ->defaults('server', 'admin')
                ->name('app_combined_docs');

            return;
        }

        $router->get('/api/docs.json', OpenApiController::class . '@__invoke')->name('app_docs');
        $router->get('/api/v5/docs.json', '\\' . OpenApiController::class)
            ->defaults('server', 'admin')
            ->name('app_admin_docs');
    }

    protected function tearDown(): void
    {
        $this->app?->make(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);

        parent::tearDown();
    }

    private function optimize(): void
    {
        $pending = $this->artisan('jsonapi:optimize');
        self::assertInstanceOf(PendingCommand::class, $pending);
        $pending->assertSuccessful()->run();
    }

    #[Test]
    #[Group('openapi')]
    public function linksPointAtTheAppsOwnDocumentRoutes(): void
    {
        $this->optimize();
        self::assertSame([], $this->logger->routeWarnings());

        $this->jsonApi()->get('/admin-api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self', ['href' => 'http://localhost/admin-api/gizmos/1', 'describedby' => '/api/v5/docs.json' . self::GIZMO_POINTER])
            ->assertJsonPath('links.describedby', ['href' => '/api/v5/docs.json' . self::GIZMO_POINTER]);

        $this->jsonApi()->get('/api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self.describedby', '/api/docs.json' . self::GIZMO_POINTER);

        // The served document's examples point at the same route.
        $this->get('/api/v5/docs.json')
            ->assertOk()
            ->assertJsonPath('components.schemas.GizmosResourceLinks.properties.self.example.describedby', '/api/v5/docs.json' . self::GIZMO_POINTER);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('combined')]
    public function combinedModePointsAtTheAppsOneDocumentRoute(): void
    {
        $this->optimize();
        self::assertSame([], $this->logger->routeWarnings());

        $this->jsonApi()->get('/api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self.describedby', '/api/combined/docs.json' . self::GIZMO_POINTER);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('documentUrl')]
    public function aConfiguredDocumentUrlStillWinsOverTheAppsRoute(): void
    {
        $this->optimize();

        $this->jsonApi()->get('/admin-api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self.describedby', 'https://docs.example.test/admin.json' . self::GIZMO_POINTER);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('noDocsRoutes')]
    public function theOptimizeStepWarnsOnceForEachServerNoRouteServes(): void
    {
        $this->optimize();

        $warnings = $this->logger->routeWarnings();
        self::assertCount(2, $warnings);
        self::assertStringContainsString('No route serves the OpenAPI document for server "default"', $warnings[0]);
        self::assertStringContainsString('No route serves the OpenAPI document for server "admin"', $warnings[1]);
        self::assertStringContainsString('jsonapi.openapi.document_url', $warnings[1]);

        $this->jsonApi()->get('/admin-api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('links.self', ['href' => 'http://localhost/admin-api/gizmos/1'])
            ->assertJsonMissingPath('links.describedby');
        $this->jsonApi()->get('/api/gizmos/1')->assertOk();

        // Requests don't repeat it.
        self::assertCount(2, $this->logger->routeWarnings());
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('hiddenDocs')]
    public function theOptimizeStepStaysQuietWhenTheDocumentIsDeliberatelyNotExposed(): void
    {
        $this->optimize();

        self::assertSame([], $this->logger->routeWarnings());
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('onlyAdminMounted')]
    public function aServerWithNoJsonApiRoutesGetsNoWarning(): void
    {
        $this->optimize();

        $warnings = $this->logger->routeWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('No route serves the OpenAPI document for server "admin"', $warnings[0]);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('combinedWithNothingMounted')]
    public function combinedModeStaysQuietWhenNoServerHasJsonApiRoutes(): void
    {
        $this->optimize();

        self::assertSame([], $this->logger->routeWarnings());
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('combined')]
    #[DefineEnvironment('noDocsRoutes')]
    public function combinedModeWarnsWhenAMountedServerHasNoDocumentRoute(): void
    {
        $this->optimize();

        $warnings = $this->logger->routeWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('No route serves the combined OpenAPI document', $warnings[0]);
    }
}
