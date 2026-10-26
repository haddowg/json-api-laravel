<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class UuidKeysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([WriterResource::class, BookResource::class, LabelResource::class]);

        $models = ['writers' => Writer::class, 'books' => Book::class, 'labels' => Label::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
