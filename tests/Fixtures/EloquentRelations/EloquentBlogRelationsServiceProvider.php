<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the isolated Eloquent blog fixture: discovery finds the three read-only resources,
 * one reference {@see EloquentDataProvider} serves posts/authors/tags at `-128`, and a
 * **morph map** with aliases (`blog_author`/`blog_tag`) deliberately distinct from the
 * JSON:API types (`authors`/`tags`) proves the morph-alias ↔ type decoupling (blueprint
 * §3g): the provider uses the alias only to resolve the model class when loading a
 * {@see \Illuminate\Database\Eloquent\Relations\MorphTo}, while core serialization resolves
 * the JSON:API type via the member object's `getType()`.
 *
 * Kept in its own directory (not under the in-memory `Relations/` fixtures, which discovery
 * recurses) so the Eloquent and in-memory blog suites never collide on a shared type.
 */
final class EloquentBlogRelationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        // Morph aliases decoupled from the JSON:API types: `blog_author` ≠ `authors`,
        // `blog_tag` ≠ `tags`. Registered (not enforced) so it composes with any other
        // morph usage in the process.
        Relation::morphMap([
            'blog_author' => Author::class,
            'blog_tag' => Tag::class,
        ]);

        $modelByType = [
            'authors' => Author::class,
            'tags' => Tag::class,
            'posts' => Post::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
