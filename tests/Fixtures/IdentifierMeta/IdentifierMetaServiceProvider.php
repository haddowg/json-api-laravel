<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the identifier meta fixture over the store named by `identifier_meta.store`
 * (`eloquent` or `memory`).
 *
 * @internal
 */
final class IdentifierMetaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class, CuratorResource::class]);

        if (config('identifier_meta.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $models = ['shelves' => Shelf::class, 'books' => Book::class, 'curators' => Curator::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    private function registerInMemory(): void
    {
        $curators = ['1' => new CuratorRecord('1', 'Ada')];
        $books = ['1' => new BookRecord('1', 'Dune'), '2' => new BookRecord('2', 'Emma')];
        $shelves = ['1' => new ShelfRecord('1', 'Fiction', $curators['1'], [$books['1'], $books['2']])];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        JsonApi::provider(new InMemoryDataProvider('shelves', $shelves, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('books', $books, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('curators', $curators, identify: $identify));
    }
}
