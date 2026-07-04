<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\Author;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\EloquentMutationsServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\Post;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\Tag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see RelationshipMutationTestCase} against the **reference Eloquent provider/persister
 * pair** over real SQL (in-memory SQLite): the {@see EloquentMutationsServiceProvider} wires
 * the pair + morph map, and the mutation arms drive `associate`/`dissociate` (BelongsTo,
 * MorphTo), the inverse-FK move (HasMany), and `sync`/`syncWithoutDetaching`/`detach`
 * (BelongsToMany) inside a transaction — refereed against the witness by the identical
 * assertions.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationshipMutationTest extends RelationshipMutationTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentMutationsServiceProvider::class,
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
        Schema::create('mut_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('mut_tags', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('mut_posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('sponsor_id')->nullable();
            $table->unsignedInteger('moderator_id')->nullable();
            $table->unsignedInteger('feature_id')->nullable();
            $table->string('feature_type')->nullable();
        });
        Schema::create('mut_post_tag', static function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
        });
        Schema::create('mut_pinned_tag', static function (Blueprint $table): void {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGraph();
    }

    /**
     * Seeds the SAME graph the in-memory witness carries: two authors, three tags, three
     * posts wiring the owner-side to-ones, the polymorphic `feature`, and the join to-many.
     */
    private function seedGraph(): void
    {
        $ada = Author::query()->create(['id' => 1, 'name' => 'Ada']);
        Author::query()->create(['id' => 2, 'name' => 'Grace']);

        $php = Tag::query()->create(['id' => 1, 'label' => 'php']);
        $json = Tag::query()->create(['id' => 2, 'label' => 'json']);
        Tag::query()->create(['id' => 3, 'label' => 'rust']);

        // Post 1 Hello: author Ada, feature the json Tag, tags [php, json].
        $hello = Post::query()->create([
            'id' => 1,
            'title' => 'Hello',
            'author_id' => 1,
            'feature_id' => $json->getKey(),
            'feature_type' => $json->getMorphClass(),
        ]);
        $hello->tags()->attach([1, 2]);

        // Post 2 World: author Grace, feature the Ada Author, tags [php].
        $world = Post::query()->create([
            'id' => 2,
            'title' => 'World',
            'author_id' => 2,
            'feature_id' => $ada->getKey(),
            'feature_type' => $ada->getMorphClass(),
        ]);
        $world->tags()->attach([1]);

        // Post 3 Empty: no relations.
        Post::query()->create(['id' => 3, 'title' => 'Empty']);
    }
}
