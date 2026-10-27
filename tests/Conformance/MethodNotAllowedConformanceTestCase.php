<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A method a JSON:API path does not accept. The router refuses it before any route
 * matches, and the answer is still a JSON:API `405` naming the accepted methods in its
 * `Allow` header, while a path that is no JSON:API endpoint keeps Laravel's own `405`.
 * The read-only `artists` and the writable `albums` are served under `api/v5` with an
 * empty `base_uri` and the atomic endpoint, beside two plain routes.
 *
 * @internal
 */
abstract class MethodNotAllowedConformanceTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', '');
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api/v5', 'middleware' => [], 'domain' => null],
        ]);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/api/v5/status', static fn(): array => ['ok' => true]);
        $router->get('/health', static fn(): array => ['ok' => true]);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:errors')]
    public function aRelationshipWriteToAReadOnlyTypeIsAJsonApi405(): void
    {
        $response = $this->send('POST', '/api/v5/artists/1/relationships/albums', ['data' => [['type' => 'albums', 'id' => '1']]]);

        $this->assertMethodNotAllowed($response, 'GET, HEAD');
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUpdateOfAReadOnlyTypeIsAJsonApi405(): void
    {
        $response = $this->send('PATCH', '/api/v5/artists/1', ['data' => ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'Renamed']]]);

        $this->assertMethodNotAllowed($response, 'GET, HEAD');
        $response->assertJsonPath('errors.0.detail', 'The method PATCH is not allowed on this path, which accepts GET, HEAD.');
    }

    #[Test]
    #[Group('spec:errors')]
    public function everyOtherWriteToAReadOnlyTypeIsAJsonApi405(): void
    {
        $this->assertMethodNotAllowed($this->send('DELETE', '/api/v5/artists/1'), 'GET, HEAD');
        $this->assertMethodNotAllowed($this->send('POST', '/api/v5/artists', ['data' => ['type' => 'artists', 'attributes' => ['name' => 'New']]]), 'GET, HEAD');
    }

    #[Test]
    #[Group('spec:errors')]
    public function aWritableTypesPathListsEveryMethodItAccepts(): void
    {
        $this->assertMethodNotAllowed($this->send('PATCH', '/api/v5/albums'), 'GET, HEAD, POST');
    }

    #[Test]
    #[Group('spec:errors')]
    public function theReadsStillServe(): void
    {
        $this->call('GET', '/api/v5/artists/1', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE])->assertOk();
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function theMethodIsRefusedBeforeTheAcceptHeaderIsNegotiated(): void
    {
        $response = $this->call('DELETE', '/api/v5/artists/1', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

        $this->assertMethodNotAllowed($response, 'GET, HEAD');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aMethodTheAtomicEndpointDoesNotAcceptRendersAsItsOtherErrorsDo(): void
    {
        $response = $this->call('GET', '/api/v5/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC]);

        $response->assertStatus(405);
        $response->assertHeader('Allow', 'POST');
        $response->assertHeader('Content-Type', self::ATOMIC);
        $response->assertJsonPath('errors.0.code', 'METHOD_NOT_ALLOWED');

        // An empty batch fails before the loop too, and the 405 carries the same media type.
        $empty = $this->call('POST', '/api/v5/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC], '{"atomic:operations": []}');
        $empty->assertStatus(400);
        $empty->assertHeader('Content-Type', self::ATOMIC);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aMethodTheAtomicEndpointDoesNotAcceptIsAPlainErrorWhenTheRequestDidNotNameTheExtension(): void
    {
        $response = $this->call('GET', '/api/v5/operations', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE]);

        $response->assertStatus(405);
        $response->assertHeader('Allow', 'POST');
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('errors.0.code', 'METHOD_NOT_ALLOWED');
    }

    #[Test]
    public function aPlainRouteUnderTheSamePrefixKeepsLaravelsOwn405(): void
    {
        $response = $this->call('POST', '/api/v5/status', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE]);

        $response->assertStatus(405);
        self::assertStringNotContainsString(self::MEDIA_TYPE, (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function aPlainRouteOutsideTheMountKeepsLaravelsOwn405(): void
    {
        $response = $this->call('DELETE', '/health');

        $response->assertStatus(405);
        self::assertStringNotContainsString(self::MEDIA_TYPE, (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function theDocumentCataloguesTheCodeButNoOperationLists405(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer('default')->toArray();

        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        self::assertArrayHasKey('MethodNotAllowedError', $schemas);

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        foreach ($paths as $item) {
            self::assertIsArray($item);
            foreach ($item as $operation) {
                if (\is_array($operation) && \is_array($operation['responses'] ?? null)) {
                    self::assertArrayNotHasKey('405', $operation['responses']);
                }
            }
        }
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertMethodNotAllowed(TestResponse $response, string $allow): void
    {
        $response->assertStatus(405);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertHeader('Allow', $allow);
        $response->assertJsonCount(1, 'errors');
        $response->assertJsonPath('errors.0.code', 'METHOD_NOT_ALLOWED');
        $response->assertJsonPath('errors.0.status', '405');
        $response->assertJsonPath('errors.0.title', 'Method Not Allowed');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, ?array $body = null): TestResponse
    {
        return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], $body === null ? null : (string) \json_encode($body));
    }
}
