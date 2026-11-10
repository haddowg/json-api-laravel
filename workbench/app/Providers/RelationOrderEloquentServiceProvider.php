<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Book;
use Workbench\App\Models\Shelf;
use Workbench\App\RelationOrder\BookResource;
use Workbench\App\RelationOrder\ShelfResource;

/**
 * The Eloquent half of the relation read order conformance wiring.
 */
final class RelationOrderEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class]);

        $modelByType = ['shelves' => Shelf::class, 'books' => Book::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
