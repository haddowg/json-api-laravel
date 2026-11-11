<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FilterDefaultsOptOut;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class EloquentFilterDefaultsOptOutTest extends FilterDefaultsOptOutTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    /**
     * Dropping every default leaves `allBooks` unordered and unscoped, so its linkage reads
     * off the model; `withArchived` still has the draft default and reads through the provider.
     */
    #[Test]
    public function onlyARelationWithNoDefaultLeftStopsReadingThroughTheProvider(): void
    {
        $server = app(ServerRegistry::class)->get();
        $books = $server->resourceFor('books');
        $relations = [];
        foreach ($server->resourceFor('shelves')->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $relations[$field->name()] = $field;
            }
        }

        self::assertTrue(RelationCriteriaFactory::readsThroughProvider($books, $relations['books']));
        self::assertTrue(RelationCriteriaFactory::readsThroughProvider($books, $relations['withArchived']));
        self::assertFalse(RelationCriteriaFactory::readsThroughProvider($books, $relations['allBooks']));
        self::assertFalse(RelationCriteriaFactory::readsThroughProvider($books, $relations['listedBooks']));
    }

    /**
     * A page of shelves reads each relation's linkage with one query for the whole page,
     * whatever defaults it drops, so the query count doesn't grow with the page.
     */
    #[Test]
    public function theLinkageStaysBatchedAcrossAPage(): void
    {
        $paths = ['/shelves', '/shelves?include=allBooks', '/shelves?include=withArchived', '/shelves?include=books'];

        $before = [];
        foreach ($paths as $path) {
            $before[$path] = $this->bookQueries($path, '1, 2');
        }

        for ($id = 3; $id <= 6; ++$id) {
            DB::table('fd_shelves')->insert(['id' => $id, 'name' => 'Shelf ' . $id]);
            DB::table('fd_books')->insert([
                ['id' => $id * 10, 'shelf_id' => $id, 'title' => 'Plain ' . $id, 'archived' => false, 'draft' => false],
                ['id' => $id * 10 + 1, 'shelf_id' => $id, 'title' => 'Archived ' . $id, 'archived' => true, 'draft' => false],
            ]);
        }

        foreach ($paths as $path) {
            self::assertSame($before[$path], $this->bookQueries($path, '1, 2, 3, 4, 5, 6'), $path);
        }

        // The opt-out relations read the books unfiltered, the others through their defaults.
        self::assertContains('select * from "fd_books" where "fd_books"."shelf_id" in (1, 2)', $before['/shelves?include=allBooks']);
        self::assertContains('select * from "fd_books" where "fd_books"."draft" = ? and "fd_books"."shelf_id" in (1, 2)', $before['/shelves?include=withArchived']);
    }

    /**
     * The queries reading books for `$path`, each asserted to cover every shelf of the page,
     * with the page's id list taken out so two page sizes compare.
     *
     * @return list<string>
     */
    private function bookQueries(string $path, string $shelfIds): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->read($path);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $queries = [];
        foreach ($log as $entry) {
            $query = $entry['query'];
            if (!\str_contains($query, '"fd_books"')) {
                continue;
            }
            self::assertStringEndsWith('"fd_books"."shelf_id" in (' . $shelfIds . ')', $query, $path);
            $queries[] = \str_replace($shelfIds, '1, 2', $query);
        }

        return $queries;
    }
}
