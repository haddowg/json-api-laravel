<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi;

use haddowg\JsonApiLaravel\Http\OpenApiController;
use haddowg\JsonApiLaravel\Http\OpenApiUiController;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentRouteLocator;
use haddowg\JsonApiLaravel\OpenApi\DocumentUrlProvider;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocumentUrlProvider::class)]
#[CoversClass(DocumentRouteLocator::class)]
final class DocumentUrlProviderTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-document-url-' . \uniqid();
    }

    protected function tearDown(): void
    {
        (new ArtifactStore($this->cacheDir))->clear();
        @\rmdir($this->cacheDir);
    }

    #[Test]
    public function thePackagesNamedRoutesWinOverAnAppRoute(): void
    {
        $router = $this->router();
        $router->get('/docs.json', OpenApiController::class)->name(OpenApiUiController::DOCUMENT_ROUTE);
        $router->get('/admin/docs.json', OpenApiController::class)->defaults('server', 'admin')->name('jsonapi.admin.openapi.json');
        $router->get('/api/docs.json', OpenApiController::class)->name('app_docs');

        $urls = $this->provider($router, ['default', 'admin']);

        self::assertSame('/docs.json', $urls->forServer());
        self::assertSame('/admin/docs.json', $urls->forServer('admin'));
    }

    #[Test]
    public function withoutThePackagesRoutesItFindsTheAppsRouteToTheController(): void
    {
        $router = $this->router();
        $router->get('/api/docs.json', OpenApiController::class . '@__invoke')->name('app_docs');
        $router->get('/api/v5/docs.json', OpenApiController::class)->defaults('server', 'admin')->name('app_admin_docs');
        $router->get('/api/reports/docs.json', [OpenApiController::class, '__invoke'])->defaults('server', 'reports')->name('app_reports_docs');
        $router->get('/api/legacy/docs.json', '\\' . OpenApiController::class . '@__invoke')->defaults('server', 'legacy')->name('app_legacy_docs');
        $router->get('/api/unnamed/docs.json', OpenApiController::class)->defaults('server', 'unnamed');
        $router->post('/api/posted/docs.json', OpenApiController::class)->defaults('server', 'posted')->name('app_posted_docs');
        $router->get('/api/other.json', static fn(): string => 'other')->defaults('server', 'other')->name('elsewhere');

        $urls = $this->provider($router, ['default', 'admin', 'reports', 'legacy', 'unnamed', 'posted', 'other']);

        self::assertSame('/api/docs.json', $urls->forServer());
        self::assertSame('/api/v5/docs.json', $urls->forServer('admin'));
        self::assertSame('/api/reports/docs.json', $urls->forServer('reports'));
        self::assertSame('/api/legacy/docs.json', $urls->forServer('legacy'));
        // A route can only be linked to by name, and only a GET serves the document.
        self::assertNull($urls->forServer('unnamed'));
        self::assertNull($urls->forServer('posted'));
        self::assertNull($urls->forServer('other'));
    }

    #[Test]
    public function aParametricRouteServesTheServersItsConstraintAccepts(): void
    {
        $router = $this->router();
        $router->get('/api/{server}/openapi.json', OpenApiController::class)->where('server', 'v5|v6')->name('app_docs');
        $router->get('/api/v6/docs.json', OpenApiController::class)->defaults('server', 'v6')->name('app_v6_docs');

        $urls = $this->provider($router, ['default', 'v5', 'v6']);

        self::assertSame('/api/v5/openapi.json', $urls->forServer('v5'));
        // A route naming the server wins over a parametric one.
        self::assertSame('/api/v6/docs.json', $urls->forServer('v6'));
        self::assertNull($urls->forServer());
    }

    #[Test]
    public function combinedModeTakesAnyRouteToTheController(): void
    {
        $router = $this->router();
        $router->get('/api/openapi.json', OpenApiController::class)->defaults('server', 'admin')->name('app_docs');

        self::assertSame('/api/openapi.json', $this->provider($router, ['default', 'admin'], combined: true)->forServer());
    }

    #[Test]
    public function aStoredScanIsReadOutsideDebugAndIgnoredInIt(): void
    {
        (new ArtifactStore($this->cacheDir))->writeDocumentRoutes([
            'default' => ['route' => 'app_stored_docs', 'parameters' => []],
            'admin' => null,
        ]);

        $router = $this->router();
        $router->get('/api/stored/docs.json', OpenApiController::class)->defaults('server', 'stored')->name('app_stored_docs');
        $router->get('/api/docs.json', OpenApiController::class)->name('app_docs');

        self::assertSame('/api/stored/docs.json', $this->provider($router, ['default', 'admin'], debug: false)->forServer());
        self::assertSame('/api/docs.json', $this->provider($router, ['default', 'admin'], debug: true)->forServer());
    }

    #[Test]
    public function aConfiguredUrlWinsOverEveryRoute(): void
    {
        $router = $this->router();
        $router->get('/api/docs.json', OpenApiController::class)->name('app_docs');
        $generator = $this->generator($router);

        $urls = new DocumentUrlProvider($generator, configuredUrl: 'https://docs.example.test/{server}.json', routes: $this->locator($router, $generator, ['default'], false, true));

        self::assertSame('https://docs.example.test/default.json', $urls->forServer());
    }

    private function router(): Router
    {
        return new Router(new Dispatcher(), new Container());
    }

    private function generator(Router $router): UrlGenerator
    {
        $router->getRoutes()->refreshNameLookups();

        return new UrlGenerator($router->getRoutes(), Request::create('http://localhost/'));
    }

    /**
     * @param list<string> $servers
     */
    private function locator(Router $router, UrlGenerator $generator, array $servers, bool $combined, bool $debug): DocumentRouteLocator
    {
        return new DocumentRouteLocator($router, $generator, new ArtifactStore($this->cacheDir), $servers, $combined, $debug);
    }

    /**
     * @param list<string> $servers
     */
    private function provider(Router $router, array $servers, bool $combined = false, bool $debug = true): DocumentUrlProvider
    {
        $generator = $this->generator($router);

        return new DocumentUrlProvider($generator, combined: $combined, routes: $this->locator($router, $generator, $servers, $combined, $debug));
    }
}
