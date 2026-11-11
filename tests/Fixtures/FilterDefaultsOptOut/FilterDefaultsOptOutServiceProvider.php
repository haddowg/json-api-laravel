<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Shelf 1 holds a plain book, an archived one and a draft; shelf 2 an archived book and
 * a plain one.
 */
final class FilterDefaultsOptOutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class]);

        if (config('filter_defaults_opt_out.store') === 'memory') {
            $books = [
                '1' => new BookRecord('1', 'Plain'),
                '2' => new BookRecord('2', 'Archived', archived: true),
                '3' => new BookRecord('3', 'Draft', draft: true),
                '4' => new BookRecord('4', 'Old', archived: true),
                '5' => new BookRecord('5', 'New'),
            ];

            JsonApi::provider(new InMemoryDataProvider('shelves', [
                '1' => new ShelfRecord('1', 'Fiction', [$books['1'], $books['2'], $books['3']]),
                '2' => new ShelfRecord('2', 'Poetry', [$books['4'], $books['5']]),
            ]));
            JsonApi::provider(new InMemoryDataProvider('books', $books));

            return;
        }

        JsonApi::provider(new EloquentDataProvider([
            'shelves' => Shelf::class,
            'books' => Book::class,
        ]));
    }
}
