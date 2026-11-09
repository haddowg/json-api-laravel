<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `shelves`, `books` and the `count` collection action over the store named by
 * `filter_families.store`, `eloquent` or `memory`, both seeded from {@see self::books()}.
 *
 * @internal
 */
final class FilterFamiliesServiceProvider extends ServiceProvider
{
    /**
     * Shelf 1 North holds books 1 to 3, shelf 2 South books 4 to 6.
     *
     * @return list<array{id: int, shelf: int, title: string, genre: string, pages: int}>
     */
    public static function books(): array
    {
        return [
            ['id' => 1, 'shelf' => 1, 'title' => 'Hello, World', 'genre' => 'fiction', 'pages' => 120],
            ['id' => 2, 'shelf' => 1, 'title' => 'Dune', 'genre' => 'scifi', 'pages' => 600],
            ['id' => 3, 'shelf' => 1, 'title' => 'Emma', 'genre' => 'classic', 'pages' => 400],
            ['id' => 4, 'shelf' => 2, 'title' => 'Hyperion', 'genre' => 'scifi', 'pages' => 480],
            ['id' => 5, 'shelf' => 2, 'title' => 'Persuasion', 'genre' => 'classic', 'pages' => 250],
            ['id' => 6, 'shelf' => 2, 'title' => 'Neuromancer', 'genre' => 'scifi', 'pages' => 270],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function shelves(): array
    {
        return [1 => 'North', 2 => 'South'];
    }

    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class, CountBooksAction::class]);

        if (config('filter_families.store') !== 'memory') {
            JsonApi::provider(new EloquentDataProvider(['shelves' => Shelf::class, 'books' => Book::class]), priority: -128);

            return;
        }

        $books = [];
        $shelves = [];
        foreach (self::shelves() as $id => $name) {
            $shelves[(string) $id] = new ShelfRecord((string) $id, $name);
        }
        foreach (self::books() as $row) {
            $book = new BookRecord((string) $row['id'], $row['title'], $row['genre'], $row['pages']);
            $books[$book->id] = $book;
            $shelves[(string) $row['shelf']]->books[] = $book;
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        JsonApi::provider(new InMemoryDataProvider('books', $books, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('shelves', $shelves, identify: $identify));
    }
}
