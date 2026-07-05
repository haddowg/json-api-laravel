<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\Keyset\CursorTokenMinter;
use haddowg\JsonApi\Collection\Keyset\InMemoryKeyset;
use haddowg\JsonApi\Collection\Keyset\KeysetResolver;
use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Song;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory cursor (keyset) arm — the ground truth the Eloquent SQL push-down must
 * match byte-for-byte (bundle ADR 0063). Drives the real mint → decode → resolve → after
 * round-trip through core's shared {@see KeysetResolver} / {@see InMemoryKeyset} /
 * {@see CursorTokenMinter} (core ADR 0123), so forward/backward navigation, the has-flags,
 * NULL ordering, and the staleness guard are all exercised end-to-end.
 *
 * @internal
 */
#[CoversClass(InMemoryDataProvider::class)]
final class InMemoryDataProviderCursorTest extends TestCase
{
    #[Test]
    public function itPagesForwardThroughAPkOnlyKeyset(): void
    {
        $provider = $this->songs();

        $first = $this->fetch($provider, new CursorWindow(2));

        self::assertSame([1, 2], $this->ids($first));
        self::assertTrue($first->hasMore);
        self::assertFalse($first->hasPrevious);
        self::assertNotNull($first->cursorAfter);

        $second = $this->fetch($provider, new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')));

        self::assertSame([3], $this->ids($second));
        self::assertFalse($second->hasMore);
        self::assertTrue($second->hasPrevious);
    }

    #[Test]
    public function itPagesBackwardViaThePreviousToken(): void
    {
        $provider = $this->songs();

        $second = $this->fetch($provider, new CursorWindow(2, after: $this->decode(
            $this->fetch($provider, new CursorWindow(2))->cursorAfter,
            'page[after]',
        )));
        self::assertNotNull($second->cursorBefore);

        // Navigating back from the second page reproduces the first page's rows in
        // natural forward order (the backward page flips the order, slices, then reverses).
        $back = $this->fetch($provider, new CursorWindow(2, before: $this->decode($second->cursorBefore, 'page[before]')));

        self::assertSame([1, 2], $this->ids($back));
        self::assertTrue($back->hasMore);
        self::assertFalse($back->hasPrevious);
    }

    #[Test]
    public function itOrdersNullsLastUnderAnAscendingSortAndPagesPastThem(): void
    {
        $provider = $this->songs();
        $sorts = [SortByField::make('rating')];

        // rating asc, NULL=largest: 5.5 (2), 9.0 (1), null (3).
        $first = $this->fetch($provider, new CursorWindow(2), $sorts, ['rating']);
        self::assertSame([2, 1], $this->ids($first));
        self::assertTrue($first->hasMore);

        $second = $this->fetch($provider, new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')), $sorts, ['rating']);
        self::assertSame([3], $this->ids($second));
    }

    #[Test]
    public function itRejectsACursorMintedUnderADifferentSortDirection(): void
    {
        $provider = $this->songs();
        $sorts = [SortByField::make('title')];

        $first = $this->fetch($provider, new CursorWindow(2), $sorts, ['title']);
        $after = $this->decode($first->cursorAfter, 'page[after]');

        // The client flipped `?sort=title` → `?sort=-title` while holding the cursor: the
        // resolved keyset direction no longer matches the token's, so it is stale (ADR 0064).
        $this->expectException(CursorStale::class);

        $this->fetch($provider, new CursorWindow(2, after: $after), $sorts, ['-title']);
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
     * @param list<SortInterface> $sorts
     * @param list<string>        $sort
     *
     * @return CursorCollectionResult<object>
     */
    private function fetch(InMemoryDataProvider $provider, CursorWindow $window, array $sorts = [], array $sort = []): CursorCollectionResult
    {
        $result = $provider->fetchCollection('songs', new CollectionCriteria(
            new QueryParameters([], [], $sort, [], []),
            sorts: $sorts,
            window: $window,
        ));

        self::assertInstanceOf(CursorCollectionResult::class, $result);

        return $result;
    }

    private function decode(?string $token, string $parameter): \haddowg\JsonApi\Pagination\CursorBoundary
    {
        self::assertNotNull($token);

        return (new CursorCodec())->decode($token, $parameter);
    }

    /**
     * @param CursorCollectionResult<object> $result
     *
     * @return list<int>
     */
    private function ids(CursorCollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Song::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
