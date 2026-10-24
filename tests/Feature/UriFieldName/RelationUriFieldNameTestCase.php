<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UriFieldName;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName\UriFieldNameServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relation URI field name suite, run identically against the Eloquent provider
 * ({@see EloquentRelationUriFieldNameTest}) and the in-memory witness
 * ({@see InMemoryRelationUriFieldNameTest}).
 *
 * `articles` serves `remarks` at `article-remarks` and `writer` at `written-by`. The links
 * and the OpenAPI document name only that segment; a request to the relation's name is
 * still answered, so a client written before the rename keeps working; any other segment
 * is a `404`.
 */
abstract class RelationUriFieldNameTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    private const string BASE_URI = 'http://localhost/api';

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
        $config->set('uri_field_name.store', $this->store());

        return [JsonApiServiceProvider::class, UriFieldNameServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
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
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('ufn_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('ufn_articles', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
        });
        Schema::create('ufn_comments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->unsignedInteger('article_id')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('ufn_authors')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
        DB::table('ufn_articles')->insert([['id' => 1, 'title' => 'Hello', 'author_id' => 1]]);
        DB::table('ufn_comments')->insert([
            ['id' => 1, 'body' => 'First', 'article_id' => 1],
            ['id' => 2, 'body' => 'Second', 'article_id' => 1],
        ]);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theLinksNameTheUriFieldName(): void
    {
        $response = $this->read('/articles/1')->assertOk();

        // The relationship member keeps the relation's name; only its URLs move.
        self::assertSame(
            [
                'self' => ['href' => self::BASE_URI . '/articles/1/relationships/article-remarks'],
                'related' => ['href' => self::BASE_URI . '/articles/1/article-remarks'],
            ],
            $response->json('data.relationships.remarks.links'),
        );
        self::assertSame(self::BASE_URI . '/articles/1/written-by', $response->json('data.relationships.writer.links.related.href'));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function theRelatedEndpointAnswersAtTheUriFieldNameAndTheName(): void
    {
        foreach (['article-remarks', 'remarks'] as $segment) {
            $response = $this->read('/articles/1/' . $segment);
            $response->assertOk();

            self::assertSame(['1', '2'], $this->ids($response), $segment);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointAnswersAtTheUriFieldNameAndTheName(): void
    {
        foreach (['article-remarks', 'remarks'] as $segment) {
            $response = $this->read('/articles/1/relationships/' . $segment);
            $response->assertOk();

            self::assertSame(['1', '2'], $this->ids($response), $segment);
            // The document's own links name the URI field name whichever path was called.
            self::assertSame(self::BASE_URI . '/articles/1/article-remarks', $response->json('links.related.href'), $segment);
        }
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aToOneRelationshipIsReplacedAtTheUriFieldNameAndTheName(): void
    {
        // Article 1 starts with author 1: move it to 2 at the URI field name, then back to
        // 1 at the name.
        foreach (['written-by' => '2', 'writer' => '1'] as $segment => $authorId) {
            $this->write('PATCH', '/articles/1/relationships/' . $segment, [
                'data' => ['type' => 'authors', 'id' => $authorId],
            ])->assertOk();

            self::assertSame($authorId, $this->read('/articles/1/written-by')->assertOk()->json('data.id'), $segment);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSegmentNoRelationAnswersToIs404(): void
    {
        foreach (['/articles/1/nothing', '/articles/1/relationships/nothing'] as $path) {
            $this->read($path)->assertNotFound();
        }
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicHrefAddressesTheRelationAtTheUriFieldNameAndTheName(): void
    {
        foreach (['written-by' => '2', 'writer' => '1'] as $segment => $authorId) {
            $response = $this->atomic([[
                'op' => 'update',
                'href' => '/api/articles/1/relationships/' . $segment,
                'data' => ['type' => 'authors', 'id' => $authorId],
            ]]);
            self::assertContains($response->getStatusCode(), [200, 204], $segment . ': ' . $response->getContent());

            self::assertSame($authorId, $this->read('/articles/1/written-by')->assertOk()->json('data.id'), $segment);
        }
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicHrefToAnUnknownSegmentFailsTheBatch(): void
    {
        $this->atomic([[
            'op' => 'update',
            'href' => '/api/articles/1/relationships/nothing',
            'data' => ['type' => 'authors', 'id' => '2'],
        ]])->assertNotFound();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentKeysThePathsByTheUriFieldName(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer()->toArray()['paths'] ?? null;
        self::assertIsArray($paths);

        self::assertArrayHasKey('/articles/{id}/article-remarks', $paths);
        self::assertArrayHasKey('/articles/{id}/relationships/article-remarks', $paths);
        self::assertArrayHasKey('/articles/{id}/written-by', $paths);
        // The name path still answers, but it is not part of the documented API.
        self::assertArrayNotHasKey('/articles/{id}/remarks', $paths);
        self::assertArrayNotHasKey('/articles/{id}/relationships/remarks', $paths);
        self::assertArrayNotHasKey('/articles/{id}/writer', $paths);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $path): TestResponse
    {
        return $this->get('/api' . $path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $path, array $document): TestResponse
    {
        return $this->call($method, '/api' . $path, [], [], [], [
            'CONTENT_TYPE' => self::MEDIA_TYPE,
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ], \json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
        ], \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
    }

    /**
     * The sorted ids of the response's primary data.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function ids(TestResponse $response): array
    {
        $ids = [];
        foreach ((array) $response->json('data') as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $ids[] = $member['id'];
        }
        \sort($ids);

        return $ids;
    }
}
