<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Book;
use Workbench\App\Domain\Shelf;
use Workbench\App\RelationOrder\BookResource;
use Workbench\App\RelationOrder\Fixtures;
use Workbench\App\RelationOrder\ShelfResource;

/**
 * The in-memory half of the relation read order conformance wiring, seeded from
 * {@see Fixtures} with every member list in storage order.
 */
final class RelationOrderInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class]);

        $books = [];
        foreach (Fixtures::books() as $id => $row) {
            $books[(string) $id] = new Book((string) $id, $row['title'], $row['year'], $row['genre']);
        }

        $shelves = [];
        foreach (Fixtures::shelves() as $id => $row) {
            $shelved = [];
            foreach (Fixtures::books() as $bookId => $book) {
                if ($book['shelf'] === $id) {
                    $shelved[] = $books[(string) $bookId];
                }
            }
            $favourites = \array_map(static fn(int $bookId): Book => $books[(string) $bookId], $row['favourites']);
            $picks = \array_map(static fn(int $bookId): Book => $books[(string) $bookId], \array_keys($row['picks']));

            $shelves[(string) $id] = new Shelf((string) $id, $row['name'], $shelved, $favourites, $picks, $favourites);
        }

        JsonApi::provider(new InMemoryDataProvider('books', $books));
        JsonApi::provider(new InMemoryDataProvider('shelves', $shelves));
    }
}
