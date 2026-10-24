<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\WriteOnlyRelations;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations\WriteOnlyRelationsServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A write-only relation is accepted on writes and never read: it renders in no response,
 * `?include`, `?withCount` and `relatedQuery` refuse it, and the OpenAPI document leaves
 * out its read schema and read endpoints. `badges` is write-only only when the request
 * carries `X-Hide-Badges`, so without the header it reads like any other relation.
 *
 * Seeded graph: box 1 (North) keeper 1, secret keeper 2, stickers [1], badges [2];
 * box 2 (South) keeper 2, secret keeper 1, stickers [2], badges [1].
 *
 * @internal
 */
abstract class WriteOnlyRelationsTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

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
        $config->set('write_only.store', $this->store());

        return [JsonApiServiceProvider::class, WriteOnlyRelationsServiceProvider::class];
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

        Schema::create('wo_keepers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('wo_stickers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('wo_boxes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->unsignedInteger('keeper_id')->nullable();
            $table->unsignedInteger('secret_keeper_id')->nullable();
        });
        Schema::create('wo_box_sticker', static function (Blueprint $table): void {
            $table->unsignedInteger('box_id');
            $table->unsignedInteger('sticker_id');
            $table->integer('position')->default(0);
        });
        Schema::create('wo_box_badge', static function (Blueprint $table): void {
            $table->unsignedInteger('box_id');
            $table->unsignedInteger('sticker_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('wo_keepers')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
        DB::table('wo_stickers')->insert([['id' => 1, 'label' => 'fragile'], ['id' => 2, 'label' => 'heavy']]);
        DB::table('wo_boxes')->insert([
            ['id' => 1, 'label' => 'North', 'keeper_id' => 1, 'secret_keeper_id' => 2],
            ['id' => 2, 'label' => 'South', 'keeper_id' => 2, 'secret_keeper_id' => 1],
        ]);
        DB::table('wo_box_sticker')->insert([
            ['box_id' => 1, 'sticker_id' => 1, 'position' => 1],
            ['box_id' => 2, 'sticker_id' => 2, 'position' => 1],
        ]);
        DB::table('wo_box_badge')->insert([
            ['box_id' => 1, 'sticker_id' => 2],
            ['box_id' => 2, 'sticker_id' => 1],
        ]);
    }

    #[Test]
    public function aWriteOnlyRelationRendersInNoResponse(): void
    {
        $collection = $this->read('/boxes');
        $one = $this->read('/boxes/1');

        foreach (['data.0', 'data.1'] as $path) {
            self::assertSame(['keeper', 'badges'], \array_keys($this->relationshipsAt($collection, $path)));
        }
        self::assertSame(['keeper', 'badges'], \array_keys($this->relationshipsAt($one, 'data')));
    }

    #[Test]
    public function aPredicateWriteOnlyRelationIsHiddenOnlyWhenItsPredicateHolds(): void
    {
        $shown = $this->read('/boxes/1');
        self::assertSame([['type' => 'stickers', 'id' => '2']], $shown->json('data.relationships.badges.data'));

        $hidden = $this->read('/boxes/1', ['X-Hide-Badges' => '1']);
        self::assertSame(['keeper'], \array_keys($this->relationshipsAt($hidden, 'data')));
    }

    #[Test]
    public function aRelatedQueryNamingAWriteOnlyRelationIsRejected(): void
    {
        foreach (['stickers', 'secretKeeper'] as $relation) {
            $response = $this->read(
                '/boxes?relatedQuery[' . $relation . '][filter][id]=1',
                ['Accept' => self::MEDIA_TYPE . '; profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"'],
                expectOk: false,
            );

            $response->assertStatus(400);
            self::assertSame('relatedQuery[' . $relation . ']', $response->json('errors.0.source.parameter'));
        }
    }

    #[Test]
    public function aRelatedQueryNamingAPredicateWriteOnlyRelationIsRejectedOnlyWhenItsPredicateHolds(): void
    {
        $accept = self::MEDIA_TYPE . '; profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"';

        $this->read('/boxes?relatedQuery[badges][sort]=label', ['Accept' => $accept]);

        $rejected = $this->read('/boxes?relatedQuery[badges][sort]=label', ['Accept' => $accept, 'X-Hide-Badges' => '1'], expectOk: false);
        $rejected->assertStatus(400);
        self::assertSame('relatedQuery[badges]', $rejected->json('errors.0.source.parameter'));
    }

    #[Test]
    public function aWriteOnlyRelationCannotBeIncludedCountedOrFetched(): void
    {
        $this->read('/boxes?include=secretKeeper', expectOk: false)->assertStatus(400);
        $this->read('/boxes?withCount=stickers', expectOk: false)->assertStatus(400);
        $this->read('/boxes/1/secretKeeper', expectOk: false)->assertNotFound();
        $this->read('/boxes/1/relationships/stickers', expectOk: false)->assertNotFound();
    }

    #[Test]
    public function aWriteOnlyRelationshipMutationAnswersNoContent(): void
    {
        $this->update('/boxes/1/relationships/stickers', ['data' => [['type' => 'stickers', 'id' => '2']]])
            ->assertNoContent();

        self::assertSame(['2'], $this->storedWriteOnlyMembers('1')[1]);
    }

    #[Test]
    public function aWriteOnlyRelationIsStillWritable(): void
    {
        $response = $this->update('/boxes/1', [
            'data' => [
                'type' => 'boxes',
                'id' => '1',
                'relationships' => [
                    'secretKeeper' => ['data' => ['type' => 'keepers', 'id' => '1']],
                    'stickers' => ['data' => [['type' => 'stickers', 'id' => '2']]],
                ],
            ],
        ]);

        $response->assertOk();
        self::assertSame(['keeper', 'badges'], \array_keys($this->relationshipsAt($response, 'data')));
        self::assertSame(['1', ['2']], $this->storedWriteOnlyMembers('1'));
    }

    #[Test]
    public function theOpenApiDocumentDescribesNoReadOfAWriteOnlyRelation(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $doc['paths'];

        foreach (['secretKeeper', 'stickers'] as $relation) {
            self::assertArrayNotHasKey('/boxes/{id}/' . $relation, $paths);
            self::assertArrayNotHasKey('get', $paths['/boxes/{id}/relationships/' . $relation] ?? []);
            self::assertArrayHasKey('patch', $paths['/boxes/{id}/relationships/' . $relation] ?? []);
        }
        self::assertArrayHasKey('get', $paths['/boxes/{id}/relationships/badges'] ?? []);

        // The read schema leaves the write-only relations out; the update request keeps them.
        $read = \json_encode($this->at($doc, 'components', 'schemas', 'BoxesResource'), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('badges', $read);
        self::assertStringNotContainsString('secretKeeper', $read);
        self::assertStringNotContainsString('stickers', $read);
        $update = \json_encode($this->at($doc, 'components', 'schemas', 'BoxesUpdateRequest'), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('secretKeeper', $update);
    }

    /**
     * The stored secret keeper id and sticker ids of a box.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    abstract protected function storedWriteOnlyMembers(string $boxId): array;

    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $path, array $headers = [], bool $expectOk = true): TestResponse
    {
        $response = $this->get('/api' . $path, ['Accept' => self::MEDIA_TYPE, ...$headers]);

        if ($expectOk) {
            $response->assertOk();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function update(string $path, array $document): TestResponse
    {
        return $this->call('PATCH', '/api' . $path, [], [], [], [
            'CONTENT_TYPE' => self::MEDIA_TYPE,
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ], \json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    protected function relationshipsAt(TestResponse $response, string $path): array
    {
        $relationships = $response->json($path . '.relationships');
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }
}
