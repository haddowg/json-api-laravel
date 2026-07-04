<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Song;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory witness's sort execution: the shared {@see CriteriaApplier} matches
 * `?sort` against the declared vocabulary (the same 400s the Eloquent provider raises)
 * and hands the whole ordered directive list to core's reference
 * {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler} in one composite,
 * most-significant-first call.
 *
 * @internal
 */
#[CoversClass(InMemoryDataProvider::class)]
#[CoversClass(CriteriaApplier::class)]
final class InMemoryDataProviderSortTest extends TestCase
{
    #[Test]
    public function itSortsAscendingAndDescending(): void
    {
        $sorts = [SortByField::make('title')];

        self::assertSame([2, 1, 3], $this->sort($sorts, ['title']));
        self::assertSame([3, 1, 2], $this->sort($sorts, ['-title']));
    }

    #[Test]
    public function itSortsByMultipleKeysMostSignificantFirst(): void
    {
        // status ascending (draft, released, released), then title breaks the released tie.
        $sorts = [SortByField::make('status'), SortByField::make('title')];

        self::assertSame([2, 1, 3], $this->sort($sorts, ['status', 'title']));
    }

    #[Test]
    public function itAppliesTheDefaultSortWhenNoSortIsRequested(): void
    {
        $result = $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: []),
            sorts: [SortByField::make('title')],
            defaultSort: [new SortDirective(SortByField::make('title'), descending: false)],
        ));

        self::assertSame([2, 1, 3], $this->ids($result));
    }

    #[Test]
    public function anExplicitSortOverridesTheDefault(): void
    {
        $result = $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: ['-title']),
            sorts: [SortByField::make('title')],
            defaultSort: [new SortDirective(SortByField::make('title'), descending: false)],
        ));

        self::assertSame([3, 1, 2], $this->ids($result));
    }

    #[Test]
    public function itRejectsAnUndeclaredSortField(): void
    {
        $this->expectException(SortParamUnrecognized::class);

        $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: ['nope']),
            sorts: [SortByField::make('title')],
        ));
    }

    #[Test]
    public function itRejectsSortingWhenNoSortsAreDeclared(): void
    {
        $this->expectException(SortingUnsupported::class);

        $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: ['title']),
        ));
    }

    #[Test]
    public function itRejectsADefaultSortNamingAnUndeclaredField(): void
    {
        // A default sort is validated against the declared vocabulary exactly as a
        // requested sort — a server-config error, not a silently dropped directive.
        $this->expectException(SortParamUnrecognized::class);

        $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: []),
            sorts: [],
            defaultSort: [new SortDirective(SortByField::make('title'), descending: false)],
        ));
    }

    /**
     * @param list<SortInterface> $sorts
     * @param list<string>        $sort
     *
     * @return list<int>
     */
    private function sort(array $sorts, array $sort): array
    {
        $result = $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(sort: $sort),
            sorts: $sorts,
        ));

        return $this->ids($result);
    }

    private function songs(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('songs', [
            '1' => new Song(1, 'The Article', 'released', 9.0, false, null),
            '2' => new Song(2, 'Article Two', 'draft', 5.5, true, null),
            '3' => new Song(3, 'Zed', 'released', null, false, null),
        ]);
    }

    /**
     * @param list<string> $sort
     */
    private function query(array $sort = []): QueryParameters
    {
        return new QueryParameters([], [], $sort, [], []);
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<int>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Song::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
