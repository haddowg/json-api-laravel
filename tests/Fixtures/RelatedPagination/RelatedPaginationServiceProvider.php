<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * One shelf holding five books, served by the in-memory provider.
 */
final class RelatedPaginationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        $books = [];
        foreach (\range(1, 5) as $n) {
            $books[(string) $n] = new Book((string) $n, 'Book ' . $n);
        }

        JsonApi::provider(new InMemoryDataProvider('books', $books));
        JsonApi::provider(new InMemoryDataProvider('shelves', [
            '1' => new Shelf('1', 'Fiction', \array_values($books)),
        ]));
    }
}
