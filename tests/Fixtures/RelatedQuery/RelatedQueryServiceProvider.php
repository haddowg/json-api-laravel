<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Shelves, books and people, over Eloquent or writable in-memory stores. Ada (1) wrote
 * Alpha (1) and Gamma (3) and mentors Grace (2), who wrote Beta (2) and mentors Alan
 * (3). Shelf 1 holds all three books and features Beta.
 */
final class RelatedQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([
            ShelfResource::class,
            BookResource::class,
            PersonResource::class,
            ShowShelfAction::class,
            MixShelvesAction::class,
        ]);

        if (config('related_query.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $models = ['shelves' => Shelf::class, 'books' => Book::class, 'people' => Person::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }

    private function registerInMemory(): void
    {
        $ada = new PersonRecord('1', 'Ada');
        $grace = new PersonRecord('2', 'Grace', $ada);
        $alan = new PersonRecord('3', 'Alan', $grace);

        $alpha = new BookRecord('1', 'Alpha', $ada);
        $beta = new BookRecord('2', 'Beta', $grace);
        $gamma = new BookRecord('3', 'Gamma', $ada);
        $ada->books = [$alpha, $gamma];
        $grace->books = [$beta];

        $stores = [
            'shelves' => ['1' => new ShelfRecord('1', 'Main', [$alpha, $beta, $gamma], $beta)],
            'books' => ['1' => $alpha, '2' => $beta, '3' => $gamma],
            'people' => ['1' => $ada, '2' => $grace, '3' => $alan],
        ];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $assignId = static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };

        $providers = [];
        foreach ($stores as $type => $items) {
            $providers[$type] = new InMemoryDataProvider($type, $items, identify: $identify, assignId: $assignId);
            JsonApi::provider($providers[$type]);
        }

        $resolver = static fn(string $type, string $id): ?object => isset($providers[$type]) ? $providers[$type]->store()->find($id) : null;
        $factories = [
            'shelves' => static fn(): ShelfRecord => new ShelfRecord(),
            'books' => static fn(): BookRecord => new BookRecord(),
            'people' => static fn(): PersonRecord => new PersonRecord(),
        ];
        foreach ($factories as $type => $factory) {
            JsonApi::persister(new InMemoryDataPersister($type, $providers[$type]->store(), $factory, $resolver));
        }
    }
}
