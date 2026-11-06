<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\OpenApiDocumentUrls;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * The YAML document routes: which are registered for each configuration, that each
 * serves its JSON counterpart as YAML, and the document URLs resolved for a request. Two servers, `default` (genres) and
 * `secure` (albums, artists).
 *
 * @internal
 */
#[Group('openapi')]
final class OpenApiYamlRouteTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private string $cacheDir = '';

    private bool $debug = true;

    private bool $exposed = false;

    private bool $combined = false;

    /** @var array<string, mixed> */
    private array $yaml = [];

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
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-yaml-' . \uniqid();

        // The #[DefineEnvironment] methods run first and set the properties read here.
        $config = self::config($app);
        $config->set('app.debug', $this->debug);
        $config->set('jsonapi.openapi.expose_in_prod', $this->exposed);
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);
        foreach ($this->yaml as $key => $value) {
            $config->set('jsonapi.openapi.yaml.' . $key, $value);
        }

        if ($this->combined) {
            // A combined document can't hold a type on two servers, so combined mode runs
            // the one server.
            $config->set('jsonapi.openapi.multi_server', 'combined');
            $config->set('jsonapi.servers', ['default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null]]);

            return;
        }

        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->resolve(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);

        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function combined($app): void
    {
        $this->combined = true;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function customPath($app): void
    {
        $this->yaml['path'] = 'openapi/spec.yaml';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function disabled($app): void
    {
        $this->yaml['enabled'] = false;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function hiddenInProd($app): void
    {
        $this->debug = false;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function exposedInProd($app): void
    {
        $this->debug = false;
        $this->exposed = true;
    }

    #[Test]
    public function each_server_serves_its_document_as_yaml(): void
    {
        $this->assertYamlOf($this->get('/docs.yaml'), $this->get('/docs.json'));
        $this->assertYamlOf($this->get('/secure/docs.yaml'), $this->get('/secure/docs.json'));

        $default = $this->parse($this->get('/docs.yaml'));
        $secure = $this->parse($this->get('/secure/docs.yaml'));
        $this->assertArrayHasKey('/genres', $this->arr($default['paths'] ?? null));
        $this->assertArrayHasKey('/albums', $this->arr($secure['paths'] ?? null));
        $this->assertArrayNotHasKey('/genres', $this->arr($secure['paths'] ?? null));
    }

    #[Test]
    public function the_document_urls_name_both_formats(): void
    {
        $urls = $this->resolve(OpenApiDocumentUrls::class);

        $default = $urls->forRequest(Request::create('https://docs.example.test/anything'));
        $this->assertSame('https://docs.example.test/docs.json', $default->json);
        $this->assertSame('https://docs.example.test/docs.yaml', $default->yaml);
        $this->assertSame([
            ['title' => 'JSON', 'url' => 'https://docs.example.test/docs.json'],
            ['title' => 'YAML', 'url' => 'https://docs.example.test/docs.yaml'],
        ], $default->downloadUrls());

        $secure = $urls->forRequest(Request::create('http://localhost/'), 'secure');
        $this->assertSame('http://localhost/secure/docs.json', $secure->json);
        $this->assertSame('http://localhost/secure/docs.yaml', $secure->yaml);
    }

    #[Test]
    #[DefineEnvironment('combined')]
    public function combined_mode_serves_the_one_combined_document(): void
    {
        $this->assertYamlOf($this->get('/docs.yaml'), $this->get('/docs.json'));
        $this->get('/default/docs.yaml')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('customPath')]
    public function the_path_is_configurable(): void
    {
        $this->assertYamlOf($this->get('/openapi/spec.yaml'), $this->get('/docs.json'));
        $this->get('/docs.yaml')->assertNotFound();
        $this->assertSame('http://localhost/openapi/spec.yaml', $this->resolve(OpenApiDocumentUrls::class)->forRequest(Request::create('http://localhost/'))->yaml);
    }

    #[Test]
    #[DefineEnvironment('disabled')]
    public function disabled_registers_no_route_and_names_no_url(): void
    {
        $this->get('/docs.json')->assertOk();
        $this->get('/docs.yaml')->assertNotFound();
        $this->get('/secure/docs.yaml')->assertNotFound();
        $this->assertNull($this->resolve(OpenApiDocumentUrls::class)->forRequest(Request::create('http://localhost/'))->yaml);
    }

    #[Test]
    #[DefineEnvironment('hiddenInProd')]
    public function the_yaml_routes_are_hidden_outside_debug_by_default(): void
    {
        $this->get('/docs.yaml')->assertNotFound();
        $this->get('/secure/docs.yaml')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('exposedInProd')]
    public function the_yaml_routes_are_exposed_in_prod_when_opted_in(): void
    {
        $this->assertYamlOf($this->get('/docs.yaml'), $this->get('/docs.json'));
        $this->assertYamlOf($this->get('/secure/docs.yaml'), $this->get('/secure/docs.json'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $yaml
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $json
     */
    private function assertYamlOf(TestResponse $yaml, TestResponse $json): void
    {
        $json->assertOk();
        $yaml->assertOk()->assertHeader('Content-Type', 'application/yaml');
        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

        $this->assertSame(
            \json_encode(\json_decode((string) $json->getContent(), false, 512, \JSON_THROW_ON_ERROR), $flags),
            \json_encode(Yaml::parse((string) $yaml->getContent(), Yaml::PARSE_OBJECT_FOR_MAP), $flags),
        );
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>
     */
    private function parse(TestResponse $response): array
    {
        return $this->arr(Yaml::parse((string) $response->getContent()));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }

    private static function config(mixed $app): Repository
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);

        return $config;
    }
}
