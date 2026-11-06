<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Http\OpenApiController;
use haddowg\JsonApiLaravel\Http\OpenApiYamlController;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\OpenApiDocumentUrls;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\DocsRoute\IgnoresRoutesServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * An app that places the routes itself can route the YAML controller for a server the
 * same way it routes the JSON one, and the document URLs then name its routes.
 *
 * @internal
 */
#[Group('openapi')]
final class OpenApiYamlAppRouteTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private string $cacheDir = '';

    private bool $yamlEnabled = true;

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
            IgnoresRoutesServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-yaml-app-' . \uniqid();

        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);
        $config->set('app.debug', true);
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);
        $config->set('jsonapi.openapi.yaml.enabled', $this->yamlEnabled);
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function yamlDisabled($app): void
    {
        $this->yamlEnabled = false;
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->prefix('api')->group(static fn(Router $router) => $router->jsonApi());
        $router->prefix('secure-api')->group(static fn(Router $router) => $router->jsonApi('secure'));

        $router->get('/api/v5/docs.json', OpenApiController::class)->defaults('server', 'secure')->name('app_secure_docs');
        $router->get('/api/v5/docs.yaml', OpenApiYamlController::class)->defaults('server', 'secure')->name('app_secure_docs_yaml');
    }

    protected function tearDown(): void
    {
        $this->resolve(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);

        parent::tearDown();
    }

    #[Test]
    public function an_app_can_route_the_yaml_controller_for_a_server(): void
    {
        $this->get('/docs.yaml')->assertNotFound();
        $this->get('/secure/docs.yaml')->assertNotFound();

        $json = $this->get('/api/v5/docs.json')->assertOk();
        $yaml = $this->get('/api/v5/docs.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml');

        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;
        $this->assertSame(
            \json_encode(\json_decode((string) $json->getContent(), false, 512, \JSON_THROW_ON_ERROR), $flags),
            \json_encode(Yaml::parse((string) $yaml->getContent(), Yaml::PARSE_OBJECT_FOR_MAP), $flags),
        );
        $this->assertArrayHasKey('/albums', $this->arrayAt(Yaml::parse((string) $yaml->getContent()), 'paths'));

        $etag = (string) $yaml->baseResponse->getEtag();
        $this->assertNotSame($json->baseResponse->getEtag(), $etag);
        $this->get('/api/v5/docs.yaml', ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function the_document_urls_name_the_apps_routes(): void
    {
        $urls = $this->resolve(OpenApiDocumentUrls::class)->forRequest(Request::create('https://docs.example.test/'), 'secure');

        $this->assertSame('https://docs.example.test/api/v5/docs.json', $urls->json);
        $this->assertSame('https://docs.example.test/api/v5/docs.yaml', $urls->yaml);
    }

    #[Test]
    #[DefineEnvironment('yamlDisabled')]
    public function the_yaml_controller_is_not_found_when_yaml_is_disabled(): void
    {
        $this->get('/api/v5/docs.json')->assertOk();
        $this->get('/api/v5/docs.yaml')->assertNotFound();
        $this->assertNull($this->resolve(OpenApiDocumentUrls::class)->forRequest(Request::create('http://localhost/'), 'secure')->yaml);
    }
}
