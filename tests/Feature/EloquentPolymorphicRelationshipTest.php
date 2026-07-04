<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations\Author;
use haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations\EloquentBlogRelationsServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations\Post;
use haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations\PostResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations\Tag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The reference Eloquent provider's **polymorphic** (morphTo + morphToMany) and
 * `belongsToMany` (incl. pivot READ) relation reads, executed as real SQL over SQLite — the
 * Eloquent twin of {@see InMemoryPolymorphicRelationshipTest}, so the two providers render
 * byte-identically. It exercises the morph-alias ↔ JSON:API-type decoupling (a stored
 * `blog_tag`/`blog_author` alias renders as `tags`/`authors`), the mixed polymorphic
 * to-many (Doctrine throws here; the Eloquent reference windows it in PHP), and the
 * `meta.pivot` READ off Eloquent's pivot accessor.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPolymorphicRelationshipTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentBlogRelationsServiceProvider::class,
        ];
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
        Schema::create('blog_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('blog_tags', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('blog_posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('feature_id')->nullable();
            $table->string('feature_type')->nullable();
        });
        Schema::create('blog_post_tag', static function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
            $table->integer('position')->default(0);
        });
        Schema::create('blog_post_members', static function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('member_id');
            $table->string('member_type');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlog();
    }

    // --- polymorphic to-one (MorphTo, morph-alias decoupled from type) --------

    public function test_polymorphic_to_one_resolves_the_members_own_type(): void
    {
        // post 1's feature is a Tag (stored morph alias `blog_tag`) rendered as `tags`;
        // post 2's is an Author (alias `blog_author`) rendered as `authors` — the morph
        // alias is decoupled from the JSON:API type.
        $tagFeature = $this->fetch('/api/posts/1/feature');
        $tagFeature->assertOk();
        self::assertSame('tags', $tagFeature->json('data.type'));
        self::assertSame('2', $tagFeature->json('data.id'));
        self::assertSame('json', $tagFeature->json('data.attributes.label'));

        $authorFeature = $this->fetch('/api/posts/2/feature');
        $authorFeature->assertOk();
        self::assertSame('authors', $authorFeature->json('data.type'));
        self::assertSame('1', $authorFeature->json('data.id'));
    }

    public function test_polymorphic_to_one_that_is_null_renders_data_null(): void
    {
        $response = $this->fetch('/api/posts/3/feature');

        $response->assertOk();
        self::assertNull($response->json('data'));
    }

    public function test_polymorphic_to_one_rejects_a_filter(): void
    {
        $this->fetch('/api/posts/1/feature?filter[label]=json')->assertStatus(400);
    }

    // --- polymorphic to-many (mixed morph set, over-parity) -------------------

    public function test_polymorphic_to_many_renders_mixed_members(): void
    {
        $response = $this->fetch('/api/posts/1/related');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $types = array_column((array) $response->json('data'), 'type');
        sort($types);
        self::assertSame(['authors', 'tags'], $types);
    }

    public function test_polymorphic_to_many_relationship_endpoint_renders_mixed_linkage(): void
    {
        $response = $this->fetch('/api/posts/1/relationships/related');

        $response->assertOk();
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        self::assertCount(2, $data);
        $identifiers = array_map(static fn(array $row): string => $row['type'] . ':' . $row['id'], $data);
        sort($identifiers);
        self::assertSame(['authors:1', 'tags:1'], $identifiers);
    }

    public function test_polymorphic_to_many_rejects_filter_and_sort(): void
    {
        $this->fetch('/api/posts/1/related?filter[label]=php')->assertStatus(400);
        $this->fetch('/api/posts/1/related?sort=label')->assertStatus(400);
    }

    public function test_include_of_a_polymorphic_relation_expands_lazily(): void
    {
        $feature = $this->fetch('/api/posts/1?include=feature');
        $feature->assertOk();
        self::assertSame('tags', $feature->json('included.0.type'));
        self::assertSame('2', $feature->json('included.0.id'));

        $related = $this->fetch('/api/posts/1?include=related');
        $related->assertOk();
        $types = array_column((array) $related->json('included'), 'type');
        sort($types);
        self::assertSame(['authors', 'tags'], $types);
    }

    // --- belongsToMany + monomorphic to-one -----------------------------------

    public function test_belongs_to_many_renders_the_related_collection(): void
    {
        $response = $this->fetch('/api/posts/1/tags');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        self::assertSame('tags', $response->json('data.0.type'));
        $ids = array_column((array) $response->json('data'), 'id');
        sort($ids);
        self::assertSame(['1', '2'], $ids);
    }

    public function test_monomorphic_to_one_that_is_null_renders_data_null(): void
    {
        $response = $this->fetch('/api/posts/3/author');

        $response->assertOk();
        self::assertNull($response->json('data'));
    }

    public function test_monomorphic_to_one_renders_the_owner(): void
    {
        $response = $this->fetch('/api/posts/1/author');

        $response->assertOk();
        self::assertSame('authors', $response->json('data.type'));
        self::assertSame('1', $response->json('data.id'));
    }

    // --- belongsToMany pivot READ (Eloquent-only capability) ------------------

    public function test_fetch_relationship_pivot_reads_the_pivot_columns(): void
    {
        // Pivot READ has no render hook in 3a (the meta.pivot linkage seam is 3b), so the
        // provider seam is exercised directly: it reads each member's `position` off the
        // Eloquent pivot accessor, keyed by the related member's id.
        $provider = new EloquentDataProvider([
            'authors' => Author::class,
            'tags' => Tag::class,
            'posts' => Post::class,
        ]);

        $post = Post::query()->findOrFail(1);
        $tags = $this->relation('tags');

        $pivot = $provider->fetchRelationshipPivot('posts', $post, $tags);

        self::assertSame(['1' => ['position' => 0], '2' => ['position' => 1]], $pivot);
    }

    public function test_fetch_relationship_pivot_is_empty_for_a_post_with_no_tags(): void
    {
        $provider = new EloquentDataProvider(['tags' => Tag::class, 'posts' => Post::class]);

        $post = Post::query()->findOrFail(3);

        self::assertSame([], $provider->fetchRelationshipPivot('posts', $post, $this->relation('tags')));
    }

    // --- belongsToMany fast-path batch (shared child, dictionary matching) -----

    public function test_belongs_to_many_batches_across_parents_with_a_shared_child(): void
    {
        // The `php` tag (1) is attached to BOTH post 1 and post 2 (a shared child); the
        // eager-pipeline fast path partitions it into each parent's own result and leaves an
        // empty post its own empty partition — one query, dictionary-matched.
        $provider = new EloquentDataProvider(['tags' => Tag::class, 'posts' => Post::class]);

        /** @var list<Post> $posts */
        $posts = Post::query()->orderBy('id')->get()->all();

        $batch = $provider->fetchRelatedCollectionBatch(
            'posts',
            $posts,
            $this->relation('tags'),
            new \haddowg\JsonApiLaravel\DataProvider\CollectionCriteria(
                new \haddowg\JsonApi\Operation\QueryParameters([], [], [], [], []),
            ),
            $this->createStub(\haddowg\JsonApi\Request\JsonApiRequestInterface::class),
        );

        self::assertSame(['1', '2'], $this->batchIds($batch, '1'));
        self::assertSame(['1'], $this->batchIds($batch, '2'));
        self::assertSame([], $this->batchIds($batch, '3'));
    }

    /**
     * The sorted wire ids of a batch partition for `$parentWireId`.
     *
     * @return list<string>
     */
    private function batchIds(\haddowg\JsonApiLaravel\DataProvider\RelatedBatch $batch, string $parentWireId): array
    {
        $ids = [];
        foreach ($batch->for($parentWireId)->items as $item) {
            self::assertInstanceOf(Tag::class, $item);
            /** @var mixed $key */
            $key = $item->getKey();
            $ids[] = \is_scalar($key) ? (string) $key : '';
        }
        sort($ids);

        return $ids;
    }

    /**
     * The declared relation named `$name` on the posts resource.
     */
    private function relation(string $name): RelationInterface
    {
        $relation = (new PostResource())->relationNamed($name);
        self::assertInstanceOf(RelationInterface::class, $relation);

        return $relation;
    }

    private function seedBlog(): void
    {
        $ada = Author::query()->create(['id' => 1, 'name' => 'Ada']);
        Author::query()->create(['id' => 2, 'name' => 'Grace']);

        $php = Tag::query()->create(['id' => 1, 'label' => 'php']);
        $json = Tag::query()->create(['id' => 2, 'label' => 'json']);

        // Post 1 'Hello': author Ada, feature the json Tag, tags [php@0, json@1], related [Ada, php].
        $hello = Post::query()->create([
            'id' => 1,
            'title' => 'Hello',
            'author_id' => 1,
            'feature_id' => $json->id,
            'feature_type' => $json->getMorphClass(),
        ]);
        $hello->tags()->attach([1 => ['position' => 0], 2 => ['position' => 1]]);
        $hello->relatedAuthors()->attach($ada->id);
        $hello->relatedTags()->attach($php->id);

        // Post 2 'World': author Grace, feature the Ada Author, tags [php], related [json].
        $world = Post::query()->create([
            'id' => 2,
            'title' => 'World',
            'author_id' => 2,
            'feature_id' => $ada->id,
            'feature_type' => $ada->getMorphClass(),
        ]);
        $world->tags()->attach([1 => ['position' => 0]]);
        $world->relatedTags()->attach($json->id);

        // Post 3 'Empty': null to-one, empty to-many.
        Post::query()->create(['id' => 3, 'title' => 'Empty', 'author_id' => null]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
