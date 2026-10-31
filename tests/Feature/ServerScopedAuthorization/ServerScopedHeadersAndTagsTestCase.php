<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\ServerScopedAuthorizationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * The response headers and OpenAPI tags a type declares apply only on the server whose
 * resource class declares them. On `default`, documents cache publicly and are
 * tagged "Public documents", and notes declare nothing. On `admin`, documents cache privately,
 * are deprecated and tagged "Admin documents", and notes carry their own caching and sunset.
 *
 * @internal
 */
abstract class ServerScopedHeadersAndTagsTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('ssa.store', $this->store());
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('jsonapi.defaults.cache_headers', ['max_age' => 5]);

        return [JsonApiServiceProvider::class, ServerScopedAuthorizationServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User(['id' => 2, 'name' => 'Admin', 'is_admin' => true]));
    }

    #[Test]
    #[Group('headers')]
    public function eachServerSendsItsOwnClassesHeaders(): void
    {
        $default = $this->read('/api/documents/1');
        self::assertStringContainsString('public', $this->header($default, 'Cache-Control'));
        self::assertStringContainsString('max-age=60', $this->header($default, 'Cache-Control'));
        self::assertFalse($default->headers->has('Deprecation'));

        $admin = $this->read('/admin-api/documents/1');
        self::assertStringContainsString('private', $this->header($admin, 'Cache-Control'));
        self::assertStringContainsString('max-age=30', $this->header($admin, 'Cache-Control'));
        self::assertSame('true', $this->header($admin, 'Deprecation'));
    }

    #[Test]
    #[Group('headers')]
    public function aServerWhoseClassDeclaresNothingGetsTheGlobalDefaults(): void
    {
        $default = $this->read('/api/notes/1');
        self::assertStringContainsString('max-age=5', $this->header($default, 'Cache-Control'));
        self::assertStringNotContainsString('public', $this->header($default, 'Cache-Control'));
        self::assertFalse($default->headers->has('Sunset'));

        $admin = $this->read('/admin-api/notes/1');
        self::assertStringContainsString('max-age=600', $this->header($admin, 'Cache-Control'));
        self::assertSame('Wed, 11 Nov 2026 00:00:00 GMT', $this->header($admin, 'Sunset'));
    }

    #[Test]
    #[Group('openapi')]
    public function eachServersDocumentCarriesItsOwnTags(): void
    {
        $default = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        $admin = $this->resolve(DocumentFactory::class)->forServer('admin')->toArray();

        self::assertSame(['Public documents'], $this->arrayAt($default, 'paths', '/documents', 'get', 'tags'));
        self::assertSame(['Public documents'], $this->arrayAt($default, 'paths', '/documents/-actions/count', 'get', 'tags'));
        self::assertSame(['Admin documents'], $this->arrayAt($admin, 'paths', '/documents', 'get', 'tags'));
        self::assertSame(['Admin documents'], $this->arrayAt($admin, 'paths', '/documents/-actions/count', 'get', 'tags'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function header(TestResponse $response, string $name): string
    {
        return (string) $response->headers->get($name, '');
    }
}
