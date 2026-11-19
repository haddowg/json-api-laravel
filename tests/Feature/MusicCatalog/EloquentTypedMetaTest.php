<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The example's albums declare a create's request meta, required inside an atomic batch,
 * and their resource objects' meta: the read-only release year and the write-only editor
 * note.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentTypedMetaTest extends Orchestra
{
    use InteractsWithOpenApiDocument;
    use PinsCatalogClock;

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, MusicCatalogEloquentServiceProvider::class];
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
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new McCatalogSeeder())->run();
        $this->actingAs(new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'can_read' => false, 'is_admin' => true]));
    }

    #[Test]
    public function anAlbumRendersItsDeclaredMeta(): void
    {
        self::assertSame(['releaseYear' => 1997], $this->get('/api/albums/1', ['Accept' => self::MEDIA_TYPE])->assertOk()->json('data.meta'));
    }

    #[Test]
    public function aCreateTakesItsDeclaredRequestAndResourceMeta(): void
    {
        $response = $this->write([
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Sourced', 'releasedAt' => '2020-01-01T00:00:00+00:00'], 'meta' => ['editorNote' => 'Remastered edition']],
            'meta' => ['source' => 'import'],
        ]);

        $response->assertCreated();
        // The note is write-only, so the created album renders without it.
        self::assertNull($response->json('data.meta.editorNote'));
    }

    #[Test]
    public function aCreateRefusesMetaTheAlbumDoesNotDeclare(): void
    {
        $response = $this->write([
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Unsourced'], 'meta' => ['releaseYear' => 2001, 'mood' => 'calm']],
            'meta' => ['source' => 'fax', 'campaign' => 'spring'],
        ]);

        $response->assertStatus(422);
        $pointers = $this->pointers($response);
        \sort($pointers);
        // A read-only member is ignored, as a read-only attribute is.
        self::assertSame(['/data/meta/mood', '/meta/campaign', '/meta/source'], $pointers);
    }

    #[Test]
    public function anAtomicCreateMustSayWhereTheAlbumCameFrom(): void
    {
        $response = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'albums', 'attributes' => ['title' => 'Batched']]],
        ], 'meta' => ['anything' => 'goes']]);
        $response->assertStatus(422);
        self::assertSame(['/atomic:operations/0/meta/source'], $this->pointers($response));

        $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'albums', 'attributes' => ['title' => 'Batched', 'releasedAt' => '2020-01-01T00:00:00+00:00']], 'meta' => ['source' => 'import']],
        ], 'meta' => ['anything' => 'goes']])->assertOk();
    }

    #[Test]
    public function theDocumentTypesTheDeclaredMeta(): void
    {
        $schemas = $this->arrayAt($this->resolve(DocumentFactory::class)->forServer()->toArray(), 'components', 'schemas');

        self::assertSame(['$ref' => '#/components/schemas/AlbumsResourceMeta'], $this->at($schemas, 'AlbumsResource', 'properties', 'meta'));
        self::assertTrue($this->at($schemas, 'AlbumsResourceMeta', 'x-resource-meta'));
        self::assertTrue($this->at($schemas, 'AlbumsCreateRequestDocument', 'x-request-meta'));
        self::assertTrue($this->at($schemas, 'AlbumsAtomicAddOperation', 'x-request-meta'));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(array $document): TestResponse
    {
        return $this->json('POST', '/api/albums', $document, ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $batch): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC], (string) \json_encode($batch));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function pointers(TestResponse $response): array
    {
        $pointers = [];
        foreach ((array) $response->json('errors') as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? null;
            self::assertIsArray($source);
            $pointer = $source['pointer'] ?? null;
            self::assertIsString($pointer);
            $pointers[] = $pointer;
        }

        return $pointers;
    }
}
