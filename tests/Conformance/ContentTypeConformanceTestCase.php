<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ContentType\ContentTypeServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A request that carries, or may carry, a body must declare its media type:
 * every `POST`, and a `PATCH`, `PUT` or `DELETE` that has a body. CRUD and relationship
 * writes, atomic batches and document or no-input actions take only the JSON:API media type;
 * an action's `accepts` list names what else it takes.
 *
 * @internal
 */
abstract class ContentTypeConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var class-string $provider */
        $provider = $this->conformanceServiceProvider();

        return [JsonApiServiceProvider::class, $provider, ContentTypeServiceProvider::class];
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

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    /**
     * @return iterable<string, array{string, string, ?string, string}>
     */
    public static function refusedRequests(): iterable
    {
        $album = '{"data":{"type":"albums","attributes":{"title":"Kid A","status":"released","releasedAt":"2000-10-02T00:00:00+00:00"}}}';
        $patch = '{"data":{"type":"albums","id":"1","attributes":{"title":"Amnesiac"}}}';

        yield 'create as plain JSON' => ['POST', '/api/albums', 'application/json', $album];
        yield 'create with no content type' => ['POST', '/api/albums', null, $album];
        yield 'update as plain text' => ['PATCH', '/api/albums/1', 'text/plain', $patch];
        yield 'delete with a JSON body' => ['DELETE', '/api/albums/1', 'application/json', '[]'];
        yield 'relationship write as plain JSON' => ['PATCH', '/api/albums/1/relationships/artist', 'application/json', '{"data":{"type":"artists","id":"1"}}'];
        yield 'atomic batch as plain JSON' => ['POST', '/api/operations', 'application/json', '{"atomic:operations":[]}'];
        yield 'no-input action with no content type' => ['POST', '/api/albums/-actions/ping', null, ''];
        yield 'no-input action as a form' => ['POST', '/api/albums/-actions/ping', 'application/x-www-form-urlencoded', 'a=1'];
        yield 'raw action as plain text' => ['POST', '/api/albums/-actions/upload-artwork', 'text/plain', 'x'];
        yield 'raw action as multipart' => ['POST', '/api/albums/-actions/upload-artwork', 'multipart/form-data; boundary=x', "--x--\r\n"];
        yield 'raw action as a form' => ['POST', '/api/albums/-actions/upload-artwork', 'application/x-www-form-urlencoded', 'a=1'];
        yield 'raw action with no content type' => ['POST', '/api/albums/-actions/upload-artwork', null, 'x'];
        yield 'csv-only action, JSON:API with a body' => ['POST', '/api/albums/-actions/reindex', self::MEDIA_TYPE, '{"data":null}'];
        yield 'csv-only action, plain text with no body' => ['POST', '/api/albums/-actions/reindex', 'text/plain', ''];
        yield 'accepts action, unlisted type' => ['POST', '/api/albums/-actions/import', 'application/json', '{}'];
        yield 'accepts action, JSON:API with an unknown parameter' => ['POST', '/api/albums/-actions/import', self::MEDIA_TYPE . '; charset=utf-8', '{}'];
    }

    #[Test]
    #[DataProvider('refusedRequests')]
    public function aBodyInAnUnacceptedMediaTypeIs415(string $method, string $uri, ?string $contentType, string $body): void
    {
        $accept = $uri === '/api/operations' ? self::ATOMIC : self::MEDIA_TYPE;

        $this->send($method, $uri, $contentType, $body, $accept)
            ->assertStatus(415)
            ->assertJsonPath('errors.0.status', '415');
    }

    #[Test]
    public function aBodylessDeleteOrReadNeedsNoContentType(): void
    {
        $this->send('GET', '/api/albums/1', 'text/plain', '')->assertOk();
        $this->send('DELETE', '/api/albums/2', null, '')->assertNoContent();
    }

    #[Test]
    public function aNoInputActionTakesTheJsonApiMediaType(): void
    {
        $this->send('POST', '/api/albums/-actions/ping', self::MEDIA_TYPE, '')->assertOk()->assertJsonPath('meta.pong', true);
    }

    #[Test]
    public function aBodylessJsonApiPostReachesAnyAction(): void
    {
        $this->send('POST', '/api/albums/-actions/reindex', self::MEDIA_TYPE, '')
            ->assertOk()
            ->assertJsonPath('meta.bytes', 0)
            ->assertJsonPath('meta.input', false);
        $this->send('POST', '/api/albums/-actions/reindex', 'text/csv', 'a,b')->assertOk()->assertJsonPath('meta.bytes', 3);
    }

    #[Test]
    public function anAcceptedBodyEntryIsReadAsARawBody(): void
    {
        $this->send('POST', '/api/albums/-actions/import-scores', 'application/json', '{"score":3}')
            ->assertOk()
            ->assertJsonPath('meta.mediaType', 'application/json')
            ->assertJsonPath('meta.body', '{"score":3}');
        $this->send('POST', '/api/albums/-actions/import-scores', 'text/plain', '3')->assertOk();
        $this->send('POST', '/api/albums/-actions/import-scores', 'text/csv', '3')->assertStatus(415);
    }

    #[Test]
    public function aRawActionWithoutAcceptsTakesAnyOtherType(): void
    {
        $this->send('POST', '/api/albums/-actions/upload-artwork', 'image/png', 'PNGDATA')
            ->assertOk()
            ->assertJsonPath('meta.mediaType', 'image/png')
            ->assertJsonPath('meta.bytes', 7);
    }

    #[Test]
    public function anAcceptsActionReadsEachTypeInItsOwnMode(): void
    {
        $document = '{"data":{"type":"albums","attributes":{"title":"Kid A","status":"released","releasedAt":"2000-10-02T00:00:00+00:00"}}}';

        $this->send('POST', '/api/albums/-actions/import', self::MEDIA_TYPE, $document)
            ->assertOk()
            ->assertJsonPath('meta.mediaType', self::MEDIA_TYPE)
            ->assertJsonPath('meta.title', 'Kid A');

        $this->send('POST', '/api/albums/-actions/import', 'text/csv; charset=utf-8', 'Amnesiac,2001')
            ->assertOk()
            ->assertJsonPath('meta.mediaType', 'text/csv')
            ->assertJsonPath('meta.title', 'Amnesiac');

        // The document entry still validates its body.
        $this->send('POST', '/api/albums/-actions/import', self::MEDIA_TYPE, '{"data":{"type":"albums","attributes":{}}}')
            ->assertStatus(422);
    }

    #[Test]
    public function theAcceptedTypesAreProjectedAsTheRequestBody(): void
    {
        $document = $this->app?->make(DocumentFactory::class)->forServer()->toArray();
        self::assertIsArray($document);

        $import = $this->at($document, 'paths', '/albums/-actions/import', 'post');
        $content = $this->at($import, 'requestBody', 'content');
        self::assertSame([self::MEDIA_TYPE, 'text/csv'], \array_keys($content));
        self::assertSame(['type' => 'string'], $this->at($content, 'text/csv', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/AlbumsCreateRequest'], $this->at($content, self::MEDIA_TYPE, 'schema'));
        self::assertArrayHasKey('415', $this->at($import, 'responses'));

        $scores = $this->at($document, 'paths', '/albums/-actions/import-scores', 'post', 'requestBody', 'content');
        self::assertSame(['application/json', 'multipart/form-data', 'text/plain'], \array_keys($scores));
        self::assertSame(['type' => 'object', 'properties' => ['score' => ['type' => 'integer']]], $this->at($scores, 'application/json', 'schema'));
        self::assertSame(['type' => 'object'], $this->at($scores, 'multipart/form-data', 'schema'));
        self::assertSame(['type' => 'string'], $this->at($scores, 'text/plain', 'schema'));

        $upload = $this->at($document, 'paths', '/albums/-actions/upload-artwork', 'post');
        self::assertSame(['application/octet-stream'], \array_keys($this->at($upload, 'requestBody', 'content')));
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<mixed>
     */
    private function at(array $array, string ...$keys): array
    {
        foreach ($keys as $key) {
            $array = $array[$key] ?? null;
            self::assertIsArray($array);
        }

        return $array;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, ?string $contentType, string $body, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $server = ['HTTP_ACCEPT' => $accept];
        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }
}
