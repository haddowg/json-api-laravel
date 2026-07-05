<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentSortHandler;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;

/**
 * The Eloquent sort handler's `ORDER BY` push-down: a {@see SortByField} directive maps
 * to a qualified `orderBy`, the whole ordered list is applied most-significant-first in
 * one composite call (so the primary key precedes the tiebreaker), and a
 * non-{@see SortByField} directive with no arm raises {@see UnsupportedSort}.
 *
 * @internal
 */
#[CoversClass(EloquentSortHandler::class)]
final class EloquentSortHandlerTest extends EloquentTestCase
{
    private EloquentSortHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EloquentSortHandler();
    }

    #[Test]
    public function itAppliesAscendingAndDescendingQualifiedColumns(): void
    {
        self::assertStringContainsString(
            'order by "artists"."name" asc',
            $this->sql([new SortDirective(SortByField::make('name'), descending: false)]),
        );

        self::assertStringContainsString(
            'order by "artists"."name" desc',
            $this->sql([new SortDirective(SortByField::make('name'), descending: true)]),
        );
    }

    #[Test]
    public function itResolvesTheStorageColumnDistinctFromTheSortKey(): void
    {
        // The sort key (`createdAt`) differs from the storage column (`created_at`).
        self::assertStringContainsString(
            'order by "artists"."created_at" asc',
            $this->sql([new SortDirective(SortByField::make('createdAt', 'created_at'), descending: false)]),
        );
    }

    #[Test]
    public function itAppliesACompositeSortMostSignificantFirst(): void
    {
        $sql = $this->sql([
            new SortDirective(SortByField::make('status'), descending: false),
            new SortDirective(SortByField::make('name'), descending: true),
        ]);

        self::assertStringContainsString(
            'order by "artists"."status" asc, "artists"."name" desc',
            $sql,
        );
    }

    #[Test]
    public function itRaisesUnsupportedSortForANonFieldSortWithNoArm(): void
    {
        $sort = new class implements SortInterface {
            public function key(): string
            {
                return 'relevance';
            }
        };

        try {
            $this->handler->apply([new SortDirective($sort, descending: false)], $this->newQuery());
            self::fail('Expected UnsupportedSort.');
        } catch (UnsupportedSort $e) {
            // The data-layer remediation hint names the Eloquent arm-seam interface (ARM_HINT),
            // so the 500 tells an author exactly which extension point runs a custom sort —
            // mirroring the bundle's DoctrineUnsupportedArmHintTest.
            self::assertStringContainsString('EloquentSortArmInterface', $e->getMessage());
        }
    }

    /**
     * @param list<SortDirective> $directives
     */
    private function sql(array $directives): string
    {
        $query = $this->newQuery();
        $this->handler->apply($directives, $query);

        return $query->toSql();
    }

    /**
     * A fresh `artists` query typed as `Builder<Model>` (built through a Model-typed
     * class-string, as the provider does) so it matches the handler's invariant
     * `SortHandlerInterface<Builder<Model>>` contract.
     *
     * @param class-string<Model> $class
     *
     * @return Builder<Model>
     */
    private function newQuery(string $class = Artist::class): Builder
    {
        return (new $class())->newQuery();
    }
}
