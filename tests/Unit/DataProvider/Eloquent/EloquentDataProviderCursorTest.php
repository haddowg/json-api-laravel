<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;

/**
 * The Eloquent provider's cursor (keyset) arm executed against real SQLite — the twin
 * of the in-memory witness (bundle ADR 0063). Covers forward/backward navigation with
 * minted tokens, the appended primary-key tiebreak that keeps a tied ordering total,
 * the datetime-cast boundary coercion (R2 — a wire ISO string must compare
 * chronologically, not lexically), the forced NULL=largest ordering (R-null parity),
 * and the stale-cursor guard.
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
final class EloquentDataProviderCursorTest extends EloquentTestCase
{
    #[Test]
    public function itPagesForwardWithMintedTokens(): void
    {
        $this->seedNamed('Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo');

        $page1 = $this->fetchCursor(new CursorWindow(2), 'name');
        self::assertSame(['Alpha', 'Bravo'], $this->names($page1));
        self::assertTrue($page1->hasMore);
        self::assertFalse($page1->hasPrevious);
        self::assertNotNull($page1->cursorAfter);

        $page2 = $this->fetchCursor(new CursorWindow(2, after: $this->decode($page1->cursorAfter)), 'name');
        self::assertSame(['Charlie', 'Delta'], $this->names($page2));
        self::assertTrue($page2->hasMore);
        self::assertTrue($page2->hasPrevious);
    }

    #[Test]
    public function itPagesBackwardToTheNaturalOrder(): void
    {
        $this->seedNamed('Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo');

        $page2 = $this->fetchCursor(
            new CursorWindow(2, after: $this->decode($this->fetchCursor(new CursorWindow(2), 'name')->cursorAfter ?? '')),
            'name',
        );
        self::assertSame(['Charlie', 'Delta'], $this->names($page2));

        // Walking back from page 2's leading edge returns page 1 in natural order.
        $back = $this->fetchCursor(new CursorWindow(2, before: $this->decode($page2->cursorBefore ?? '')), 'name');
        self::assertSame(['Alpha', 'Bravo'], $this->names($back));
        self::assertTrue($back->hasMore);
    }

    #[Test]
    public function itAppendsThePrimaryKeyTiebreakOnATiedOrdering(): void
    {
        // Every row ties on `name`, so only the appended id keeps the order total and
        // paging free of gaps/dupes.
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            Artist::query()->create(['name' => 'Same', 'slug' => $slug, 'track_count' => 0]);
        }

        $page1 = $this->fetchCursor(new CursorWindow(2), 'name');
        $page2 = $this->fetchCursor(new CursorWindow(2, after: $this->decode($page1->cursorAfter ?? '')), 'name');

        self::assertSame([1, 2], $this->ids($page1));
        self::assertSame([3, 4], $this->ids($page2));
    }

    #[Test]
    public function itComparesADatetimeBoundaryChronologicallyNotLexically(): void
    {
        // R2: the wire ISO boundary must be coerced back to a date so the SQL compare is
        // chronological. Non-monotonic-lexical dates would page wrongly if bound raw.
        Artist::query()->create(['name' => 'Old', 'slug' => 'old', 'created_at' => new \DateTimeImmutable('1990-06-15T09:00:00+00:00')]);
        Artist::query()->create(['name' => 'Mid', 'slug' => 'mid', 'created_at' => new \DateTimeImmutable('2000-01-01T00:00:00+00:00')]);
        Artist::query()->create(['name' => 'New', 'slug' => 'new', 'created_at' => new \DateTimeImmutable('2010-12-31T23:59:59+00:00')]);

        $page1 = $this->fetchCursor(new CursorWindow(1), 'createdAt', 'created_at');
        self::assertSame(['Old'], $this->names($page1));

        $page2 = $this->fetchCursor(new CursorWindow(1, after: $this->decode($page1->cursorAfter ?? '')), 'createdAt', 'created_at');
        self::assertSame(['Mid'], $this->names($page2));
    }

    #[Test]
    public function itForcesNullLargestOrdering(): void
    {
        // NULL=largest, ascending: non-nulls first (by value), nulls last.
        Artist::query()->create(['name' => 'Zed', 'slug' => 'z', 'website' => 'https://z.example']);
        Artist::query()->create(['name' => 'Nil', 'slug' => 'n', 'website' => null]);
        Artist::query()->create(['name' => 'Ace', 'slug' => 'a', 'website' => 'https://a.example']);

        $page = $this->fetchCursor(new CursorWindow(5), 'website');

        // Ordered by website asc (a.example, z.example) then the null row last.
        self::assertSame(['Ace', 'Zed', 'Nil'], $this->names($page));
    }

    #[Test]
    public function itRejectsAStaleCursorWhoseSortChanged(): void
    {
        $this->seedNamed('Alpha', 'Bravo', 'Charlie');

        // A cursor minted under sort=name, reused under sort=slug: the resolved keyset
        // columns (slug, id) no longer match the boundary's (name, id).
        $nameCursor = $this->decode($this->fetchCursor(new CursorWindow(2), 'name')->cursorAfter ?? '');

        $this->expectException(CursorStale::class);
        $this->fetchCursor(new CursorWindow(2, after: $nameCursor), 'slug');
    }

    private function seedNamed(string ...$names): void
    {
        foreach ($names as $name) {
            Artist::query()->create(['name' => $name, 'slug' => \strtolower($name), 'track_count' => 0]);
        }
    }

    private function decode(string $token): CursorBoundary
    {
        return (new CursorCodec())->decode($token, 'page[after]');
    }

    /**
     * @return CursorCollectionResult<object>
     */
    private function fetchCursor(CursorWindow $window, string $sortKey, ?string $column = null): CursorCollectionResult
    {
        $provider = new EloquentDataProvider(['artists' => Artist::class]);

        /** @var list<SortInterface> $sorts */
        $sorts = [SortByField::make($sortKey, $column ?? $sortKey)];

        $result = $provider->fetchCollection('artists', new CollectionCriteria(
            new QueryParameters([], [], [$sortKey], [], []),
            [],
            $sorts,
            $window,
        ));

        self::assertInstanceOf(CursorCollectionResult::class, $result);

        return $result;
    }

    /**
     * @param CursorCollectionResult<object> $result
     *
     * @return list<string>
     */
    private function names(CursorCollectionResult $result): array
    {
        $names = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Artist::class, $item);
            /** @var mixed $name */
            $name = $item->getAttribute('name');
            $names[] = (string) (\is_scalar($name) ? $name : '');
        }

        return $names;
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
            self::assertInstanceOf(Artist::class, $item);
            /** @var mixed $id */
            $id = $item->getAttribute('id');
            $ids[] = (int) (\is_numeric($id) ? $id : 0);
        }

        return $ids;
    }
}
