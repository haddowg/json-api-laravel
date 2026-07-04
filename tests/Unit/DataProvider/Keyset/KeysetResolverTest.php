<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Keyset;

use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared keyset-column resolver: reads the active sort off the criteria exactly as the
 * plain applier (same 400s), appends the primary key as the final total-order column, and
 * enforces cursor staleness. Both providers resolve columns from this one class so SQL and
 * PHP windowing cannot drift (bundle ADR 0063/0064).
 *
 * @internal
 */
#[CoversClass(KeysetResolver::class)]
final class KeysetResolverTest extends TestCase
{
    private KeysetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new KeysetResolver();
    }

    #[Test]
    public function aPkOnlyKeysetUsesTheDefaultPkDirection(): void
    {
        $columns = $this->resolver->resolve($this->criteria(), 'id');
        self::assertEquals([new KeysetColumn('id', false)], $columns);

        $descending = $this->resolver->resolve($this->criteria(), 'id', pkDefaultDescending: true);
        self::assertEquals([new KeysetColumn('id', true)], $descending);
    }

    #[Test]
    public function itAppendsThePkAfterTheActiveSortFollowingTheLastDirection(): void
    {
        $criteria = $this->criteria(sort: ['-name'], sorts: [SortByField::make('name')]);

        // A trailing descending directive makes the appended PK tiebreak descending too,
        // so the total order stays monotone.
        self::assertEquals(
            [new KeysetColumn('name', true), new KeysetColumn('id', true)],
            $this->resolver->resolve($criteria, 'id'),
        );
    }

    #[Test]
    public function itDoesNotAppendThePkWhenTheClientAlreadySortsByIt(): void
    {
        $criteria = $this->criteria(sort: ['id'], sorts: [SortByField::make('id')]);

        self::assertEquals([new KeysetColumn('id', false)], $this->resolver->resolve($criteria, 'id'));
    }

    #[Test]
    public function itRejectsANonFieldSort(): void
    {
        $computed = new class implements SortInterface {
            public function key(): string
            {
                return 'computed';
            }
        };

        $this->expectException(UnsupportedSort::class);

        $this->resolver->resolve($this->criteria(sort: ['computed'], sorts: [$computed]), 'id');
    }

    #[Test]
    public function itValidatesTheRequestedSortLikeThePlainPath(): void
    {
        try {
            $this->resolver->resolve($this->criteria(sort: ['nope'], sorts: [SortByField::make('name')]), 'id');
            self::fail('expected ' . SortParamUnrecognized::class);
        } catch (SortParamUnrecognized) {
        }

        $this->expectException(SortingUnsupported::class);
        $this->resolver->resolve($this->criteria(sort: ['name']), 'id');
    }

    #[Test]
    public function assertFreshAcceptsAMatchingBoundary(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['name' => 'x', 'id' => 1], true, ['name' => false, 'id' => false]);

        $this->resolver->assertFresh($boundary, $columns, 'page[after]');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertFreshRejectsADifferentColumnSet(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['id' => 1], true, ['id' => false]);

        $this->expectException(CursorStale::class);
        $this->resolver->assertFresh($boundary, $columns, 'page[after]');
    }

    #[Test]
    public function assertFreshRejectsAFlippedDirection(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['name' => 'x', 'id' => 1], true, ['name' => true, 'id' => true]);

        $this->expectException(CursorStale::class);
        $this->resolver->assertFresh($boundary, $columns, 'page[after]');
    }

    /**
     * @param list<string>        $sort
     * @param list<SortInterface> $sorts
     * @param list<SortDirective> $defaultSort
     */
    private function criteria(array $sort = [], array $sorts = [], array $defaultSort = []): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], $sort, [], []),
            sorts: $sorts,
            defaultSort: $defaultSort,
        );
    }
}
