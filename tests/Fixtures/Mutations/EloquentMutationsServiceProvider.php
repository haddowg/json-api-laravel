<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the writable Eloquent mutation fixture: discovery finds the three writable resources,
 * one reference {@see EloquentDataProvider}/{@see EloquentDataPersister} pair serves
 * posts/authors/tags at `-128`, and a morph map (aliases equal to the JSON:API types here,
 * keeping the `feature` MorphTo round-trip simple) lets the polymorphic to-one resolve its
 * stored `feature_type` back to a model.
 */
final class EloquentMutationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        Relation::morphMap([
            'authors' => Author::class,
            'tags' => Tag::class,
        ]);

        $modelByType = [
            'posts' => Post::class,
            'authors' => Author::class,
            'tags' => Tag::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
