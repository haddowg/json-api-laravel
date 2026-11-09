<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifierMeta;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta\IdentifierMetaServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation whose `identifierMeta()` declares its members renders the resolver's values on
 * each linkage identifier and types them in the OpenAPI document, beside any pivot.
 *
 * Seeded graph: shelf 1 (Fiction) curated by curator 1, holding books 1 and 2 at positions
 * 1 and 2.
 *
 * @internal
 */
abstract class IdentifierMetaTestCase extends Orchestra
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
        $config->set('identifier_meta.store', $this->store());

        return [JsonApiServiceProvider::class, IdentifierMetaServiceProvider::class];
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
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('idm_curators', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('idm_books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('idm_shelves', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->unsignedInteger('curator_id')->nullable();
        });
        Schema::create('idm_shelf_book', static function (Blueprint $table): void {
            $table->unsignedInteger('shelf_id');
            $table->unsignedInteger('book_id');
            $table->integer('position')->default(0);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('idm_curators')->insert([['id' => 1, 'name' => 'Ada']]);
        DB::table('idm_books')->insert([['id' => 1, 'title' => 'Dune'], ['id' => 2, 'title' => 'Emma']]);
        DB::table('idm_shelves')->insert([['id' => 1, 'label' => 'Fiction', 'curator_id' => 1]]);
        DB::table('idm_shelf_book')->insert([
            ['shelf_id' => 1, 'book_id' => 1, 'position' => 1],
            ['shelf_id' => 1, 'book_id' => 2, 'position' => 2],
        ]);
    }

    #[Test]
    public function theResourceLinkageCarriesTheResolverValues(): void
    {
        $shelf = $this->read('/shelves/1');

        self::assertSame(
            ['type' => 'curators', 'id' => '1', 'meta' => ['role' => 'curator of Fiction']],
            $shelf->json('data.relationships.curator.data'),
        );
        self::assertSame('1-1', $shelf->json('data.relationships.books.data.0.meta.slot'));
        self::assertSame('1-2', $shelf->json('data.relationships.books.data.1.meta.slot'));
        self::assertTrue($shelf->json('data.relationships.books.data.0.meta.featured'));
        self::assertFalse($shelf->json('data.relationships.books.data.1.meta.featured'));
    }

    #[Test]
    public function theRelationshipEndpointCarriesTheResolverValues(): void
    {
        $linkage = $this->read('/shelves/1/relationships/books');

        self::assertSame('1-1', $linkage->json('data.0.meta.slot'));
        self::assertSame('1-2', $linkage->json('data.1.meta.slot'));
        self::assertTrue($linkage->json('data.0.meta.featured'));
    }

    #[Test]
    public function theOpenApiDocumentTypesTheDeclaredMembers(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();

        self::assertSame(28, $this->at($doc, 'info', 'x-generator', 'contract'));
        self::assertSame(['role'], \array_keys($this->arrayAt($doc, 'components', 'schemas', 'ShelvesCuratorIdentifierMeta', 'properties')));
        self::assertSame(['slot', 'featured'], \array_keys($this->arrayAt($doc, 'components', 'schemas', 'ShelvesBooksIdentifierMeta', 'properties')));

        // On the pivot relation the declared members and `pivot` are siblings in one `meta`.
        self::assertSame(
            ['allOf' => [
                ['$ref' => '#/components/schemas/ShelvesBooksIdentifierMeta'],
                ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ShelvesBooksPivot']]],
            ]],
            $this->arrayAt($doc, 'components', 'schemas', 'ShelvesBooksRelationship', 'properties', 'data', 'items', 'allOf', 1, 'properties', 'meta'),
        );
        // A write carries only the request pivot.
        self::assertSame(
            ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ShelvesBooksWritePivot']]],
            $this->arrayAt($doc, 'components', 'schemas', 'ShelvesBooksWriteRelationship', 'properties', 'data', 'items', 'allOf', 1, 'properties', 'meta'),
        );
        self::assertSame(
            ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ShelvesCuratorIdentifierMeta']]],
            $this->arrayAt($doc, 'components', 'schemas', 'ShelvesCuratorRelationship', 'properties', 'data', 'anyOf', 0, 'allOf', 1),
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $path, array $headers = []): TestResponse
    {
        $response = $this->get('/api' . $path, ['Accept' => self::MEDIA_TYPE, ...$headers]);
        $response->assertOk();

        return $response;
    }
}
