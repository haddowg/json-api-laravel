<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\InMemoryBatch\BatchChild;
use haddowg\JsonApiLaravel\Tests\Fixtures\InMemoryBatch\BatchParent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory witness's WINDOWED batched relation fetch — the PK-tiebreak half of the
 * SQL-vs-`WindowExecutor` determinism referee named by the PLAN watch item. When a page of
 * children ties on every requested sort key, the witness appends a synthetic PK sort
 * ({@see InMemoryDataProvider::withPkTiebreak()}), so the windowed partition resolves ties by
 * id ascending — the SAME final `id ASC` level the Eloquent native ROW_NUMBER batch appends
 * to its ORDER BY (PLAN decision 9). This exercises that arm directly (the Eloquent windowed
 * batch deliberately throws until its 3b consumer exists), so a regression in the tiebreak
 * surfaces here rather than only when 3b builds its sole consumer.
 *
 * @internal
 */
#[CoversClass(InMemoryDataProvider::class)]
final class InMemoryRelationBatchTest extends TestCase
{
    #[Test]
    public function aWindowedBatchResolvesTiedChildrenByIdAscendingAcrossParents(): void
    {
        // Both parents' children ALL share rank 5 and are seeded OUT of id order, so a
        // stable sort alone would preserve insertion order — only the appended PK tiebreak
        // makes the window id-ascending.
        $p1 = new BatchParent('p1', [new BatchChild('3', 5), new BatchChild('1', 5), new BatchChild('2', 5)]);
        $p2 = new BatchParent('p2', [new BatchChild('20', 5), new BatchChild('10', 5)]);

        $provider = new InMemoryDataProvider(
            'parents',
            ['p1' => $p1, 'p2' => $p2],
            identify: static fn(object $parent): string => $parent instanceof BatchParent ? $parent->id : '',
        );

        $batch = $provider->fetchRelatedCollectionBatch(
            'parents',
            [$p1, $p2],
            HasMany::make('children', 'children')->build(),
            $this->windowedCriteria(new OffsetWindow(0, 2)),
            $this->createStub(JsonApiRequestInterface::class),
        );

        // First page of each partition, IN ORDER: id-ascending on the tie, NOT insertion
        // order ([3, 1] / [20, ...]) — proof the tiebreak fired.
        self::assertSame(['1', '2'], $this->ids($batch->for('p1')));
        self::assertSame(['10', '20'], $this->ids($batch->for('p2')));
    }

    #[Test]
    public function theWindowOffsetContinuesTheIdAscendingTiebreakOrder(): void
    {
        $p1 = new BatchParent('p1', [new BatchChild('3', 5), new BatchChild('1', 5), new BatchChild('2', 5)]);

        $provider = new InMemoryDataProvider(
            'parents',
            ['p1' => $p1],
            identify: static fn(object $parent): string => $parent instanceof BatchParent ? $parent->id : '',
        );

        $batch = $provider->fetchRelatedCollectionBatch(
            'parents',
            [$p1],
            HasMany::make('children', 'children')->build(),
            $this->windowedCriteria(new OffsetWindow(2, 2)),
            $this->createStub(JsonApiRequestInterface::class),
        );

        // Offset 2 under (rank ASC, id ASC) yields the third child, id 3.
        self::assertSame(['3'], $this->ids($batch->for('p1')));
    }

    private function windowedCriteria(OffsetWindow $window): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], ['rank'], [], []),
            sorts: [SortByField::make('rank', 'rank')],
            window: $window,
        );
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(BatchChild::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
