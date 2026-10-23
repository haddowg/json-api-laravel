<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the `books`/`authors`/`countries` fixture over the store named by `flatten.store`
 * (`eloquent` or `memory`). The in-memory stores share one snapshot coordinator, so an atomic
 * batch that writes a book rolls back the authors and countries its flattened writes changed.
 *
 * @internal
 */
final class FlattenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([BookResource::class, AuthorResource::class, CountryResource::class]);

        if (config('flatten.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $models = ['books' => Book::class, 'authors' => Author::class, 'countries' => Country::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    private function registerInMemory(): void
    {
        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $wonderland = new CountryRecord('1', 'Wonderland');
        $ada = new AuthorRecord('1', 'Ada Lovelace', $wonderland);
        $grace = new AuthorRecord('2', 'Grace Hopper', $wonderland);
        $books = [
            '1' => new BookRecord('1', 'Algorithms', $ada, $ada),
            '2' => new BookRecord('2', 'Compilers', $grace, $grace),
            '3' => new BookRecord('3', 'Orphan'),
        ];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $assignId = static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };

        $countries = new InMemoryDataProvider('countries', ['1' => $wonderland], identify: $identify, assignId: $assignId, coordinator: $coordinator);
        $authors = new InMemoryDataProvider('authors', ['1' => $ada, '2' => $grace], identify: $identify, assignId: $assignId, coordinator: $coordinator);
        $bookProvider = new InMemoryDataProvider('books', $books, identify: $identify, assignId: $assignId, coordinator: $coordinator);

        JsonApi::provider($countries);
        JsonApi::provider($authors);
        JsonApi::provider($bookProvider);

        $resolver = static fn(string $type, string $id): ?object => $type === 'authors' ? $authors->store()->find($id) : null;
        JsonApi::persister(new InMemoryDataPersister('books', $bookProvider->store(), static fn(): BookRecord => new BookRecord(), $resolver));
        JsonApi::persister(new InMemoryDataPersister('authors', $authors->store(), static fn(): AuthorRecord => new AuthorRecord()));
        JsonApi::persister(new InMemoryDataPersister('countries', $countries->store(), static fn(): CountryRecord => new CountryRecord()));
    }
}
