<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\JsonApi\AlbumResource;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Playlist;
use Workbench\App\Models\Track;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * The Eloquent provider's WINDOWED multi-parent relation batch — the Relationship Queries
 * profile's SQL push-down (PLAN decision 9, ADR 0006), executed as real SQL over SQLite. It
 * replaces the Phase-3a throwing seam with `Builder::groupLimit` (`ROW_NUMBER() OVER (PARTITION
 * BY <parent FK> ORDER BY <relation order>, <pk>)`): one query bounds every parent's related
 * partition to page 1, deterministic on ties through the appended primary-key tiebreak.
 *
 * These tests PIN the generated SQL (the row-number window, the partition column, the sort +
 * id tiebreak, the row cap, and the bindings) and prove correctness against SQLite: per-parent
 * bounding, `hasMore`, the real zero-filled per-parent total, NULL ordering matching the
 * witness, empty partitions, and the `belongsToMany` window through the pivot join. The
 * SQL-vs-witness tie determinism is refereed by {@see WindowedBatchDeterminismTest}.
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
final class EloquentWindowedRelationBatchTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    // --- the generated SQL (row-number / partition / order / tiebreak / bindings) ---

    #[Test]
    public function itPushesDownToARowNumberPartitionWindowedByTheParentForeignKey(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums')->build(),
            $this->windowedCriteria(new OffsetWindow(0, 2), countable: false),
            $this->request(),
        );

        $sql = $this->normalise($this->windowQuery());

        // The derived-table ROW_NUMBER window, partitioned by the qualified parent FK.
        self::assertStringContainsString('row_number() over (partition by "albums"."artist_id"', $sql);
        // The relation's default order (released_at DESC) then the deterministic id tiebreak.
        self::assertStringContainsString('order by "albums"."released_at" desc, "albums"."id" asc', $sql);
        // A count-free window probes limit + 1 (2 + 1 = 3) rows per partition for the hasMore signal.
        self::assertStringContainsString('as "laravel_table" where "laravel_row" <= 3', $sql);
        // Eloquent raw-inlines an all-integer parent IN-list (whereIntegerInRaw), so the six
        // seeded artist keys appear literally in the SQL and the query carries NO bindings (the
        // row cap is inlined too) — pinning the bindings for the integer-key case.
        self::assertStringContainsString('"albums"."artist_id" in (1, 2, 3, 4, 5, 6)', $sql);
        self::assertSame([], $this->windowBindings());
    }

    #[Test]
    public function aCountableWindowCapsAtTheLimitWithoutTheProbeRow(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums')->build(),
            $this->windowedCriteria(new OffsetWindow(0, 2), countable: true),
            $this->request(),
        );

        // A countable window bounds to exactly `limit` rows (the total rides the separate
        // grouped COUNT), so the cap is 2 not 3 — no probe row.
        self::assertStringContainsString('where "laravel_row" <= 2', $this->normalise($this->windowQuery()));
    }

    // --- per-parent bounding + hasMore + empty partitions ---------------------

    #[Test]
    public function itBoundsEachParentToPageOneCountFreeWithHasMore(): void
    {
        // released_at DESC (albums default), id ASC tiebreak. Radiohead(1) owns 4 albums →
        // page 1 = in rainbows(6), amnesiac(7) + a further page; Portishead(2) owns 1;
        // Massive Attack(3) owns 2 (exactly the page, no further page); 4/5/6 own none.
        $batch = $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums')->build(),
            $this->windowedCriteria(new OffsetWindow(0, 2), countable: false),
            $this->request(),
        );

        self::assertSame(['6', '7'], $this->ids($batch->for('1')));
        self::assertTrue($batch->for('1')->hasMore);

        self::assertSame(['2'], $this->ids($batch->for('2')));
        self::assertFalse($batch->for('2')->hasMore);

        self::assertSame(['4', '5'], $this->sortedIds($batch->for('3')));
        self::assertFalse($batch->for('3')->hasMore);

        // An empty partition: an absent parent fills with an empty count-free result.
        self::assertSame([], $this->ids($batch->for('4')));
        self::assertFalse($batch->for('4')->hasMore);
    }

    #[Test]
    public function itReportsTheRealPerParentTotalZeroFilledWhenCountable(): void
    {
        $batch = $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums')->build(),
            $this->windowedCriteria(new OffsetWindow(0, 2), countable: true),
            $this->request(),
        );

        // Bounded to the page, but the total is the FULL per-parent cardinality (not the page
        // size): Radiohead 4, Portishead 1, Massive Attack 2 — and zero-filled for an empty
        // partition (never a missing key).
        self::assertSame(['6', '7'], $this->ids($batch->for('1')));
        self::assertSame(4, $batch->for('1')->total);
        self::assertSame(1, $batch->for('2')->total);
        self::assertSame(2, $batch->for('3')->total);
        self::assertSame(0, $batch->for('5')->total);
    }

    // --- NULL ordering (matches the witness's `<=>`: nulls first ASC / last DESC) ---

    #[Test]
    public function itOrdersNullsFirstOnAscendingLikeTheWitness(): void
    {
        // A fresh artist whose albums carry a null rating (7), then 4.5 and 2.0 — sorted by the
        // nullable rating ASC the null sorts FIRST (SQLite default == the witness's `null <=>
        // value` = null-smallest), the id tiebreak ordering any further ties.
        $artist = $this->seedRatedAlbums();

        $ascending = $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            [$artist],
            HasMany::make('albums', 'albums')->build(),
            $this->sortedWindow('rating', 'average_rating', descending: false, window: new OffsetWindow(0, 2)),
            $this->request(),
        );

        // Null first, then the lowest non-null rating (2.0).
        self::assertSame(['201', '203'], $this->ids($ascending->for($this->key($artist))));

        $descending = $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            [$artist],
            HasMany::make('albums', 'albums')->build(),
            $this->sortedWindow('rating', 'average_rating', descending: true, window: new OffsetWindow(0, 3)),
            $this->request(),
        );

        // Descending: highest rating first, the null LAST (never before a value) — the whole
        // partition of 3 in order 4.5, 2.0, null.
        self::assertSame(['202', '203', '201'], $this->ids($descending->for($this->key($artist))));
    }

    // --- belongsToMany windows through the pivot join -------------------------

    #[Test]
    public function itWindowsABelongsToManyThroughThePivotJoinPartitionedPerParent(): void
    {
        // The shared conformance seed joins playlist 1 → tracks 1/2/3/4, playlist 2 → track 1,
        // playlist 3 → none, over playlist_track. Windowed to 2 per parent, ordered by the
        // track id tiebreak (no explicit sort — the bare join over track columns).
        $playlists = Playlist::query()->orderBy('id')->get()->all();

        $batch = $this->pivotProvider()->fetchRelatedCollectionBatch(
            'playlists',
            \array_values($playlists),
            BelongsToMany::make('tracks', 'tracks')->build(),
            $this->bareWindow(new OffsetWindow(0, 2)),
            $this->request(),
        );

        // The group-limit wraps the whole pivot-join query; the outer SELECT * re-exposes the
        // pivot FK so match() still partitions per playlist. Page 1 by track id ASC.
        self::assertSame(['1', '2'], $this->ids($batch->for('1')));
        self::assertTrue($batch->for('1')->hasMore);
        self::assertSame(['1'], $this->ids($batch->for('2')));
        self::assertFalse($batch->for('2')->hasMore);
        // The empty playlist partitions to nothing.
        self::assertSame([], $this->ids($batch->for('3')));
    }

    private function provider(): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'artists' => Artist::class,
            'albums' => Album::class,
        ]);
    }

    private function pivotProvider(): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'playlists' => Playlist::class,
            'tracks' => Track::class,
        ]);
    }

    /**
     * A windowed criteria over the albums resource's declared sort vocabulary + default order,
     * so the push-down resolves the SAME order the related endpoint (and the witness) does.
     */
    private function windowedCriteria(OffsetWindow $window, bool $countable): CollectionCriteria
    {
        $albumResource = new AlbumResource();

        return new CollectionCriteria(
            new QueryParameters([], [], [], [], []),
            sorts: $albumResource->allSorts(),
            window: $window,
            defaultSort: $albumResource->defaultSort(),
            wantsCount: $countable,
        );
    }

    /**
     * A windowed criteria with NO declared sort — the applier applies nothing, so only the
     * appended primary-key tiebreak orders the partition (the bare belongsToMany join case).
     */
    private function bareWindow(OffsetWindow $window): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], [], [], []),
            window: $window,
        );
    }

    /**
     * A windowed criteria that requests one explicit `SortByField` over a (possibly nullable)
     * column, so the NULL-ordering + tiebreak discipline is exercised directly.
     */
    private function sortedWindow(string $key, string $column, bool $descending, OffsetWindow $window): CollectionCriteria
    {
        $sort = SortByField::make($key, $column);

        return new CollectionCriteria(
            new QueryParameters([], [], [$descending ? '-' . $key : $key], [], []),
            sorts: [$sort],
            window: $window,
            defaultSort: [new SortDirective($sort, $descending)],
        );
    }

    /**
     * Seeds a fresh artist owning three albums with explicit ids (201 null-rated, 202 rated
     * 4.5, 203 rated 2.0) for the NULL-ordering assertion.
     */
    private function seedRatedAlbums(): Artist
    {
        $artist = Artist::query()->create([
            'name' => 'Null Rater',
            'slug' => 'null-rater',
            'track_count' => 0,
            'created_at' => new \DateTimeImmutable('2020-01-01T00:00:00Z'),
        ]);

        $artistId = $artist->getKey();
        Album::query()->insert([
            ['id' => 201, 'artist_id' => $artistId, 'title' => 'Null Rated', 'average_rating' => null, 'status' => 'live', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
            ['id' => 202, 'artist_id' => $artistId, 'title' => 'High Rated', 'average_rating' => 4.5, 'status' => 'live', 'explicit' => false, 'released_at' => '2020-01-02 00:00:00'],
            ['id' => 203, 'artist_id' => $artistId, 'title' => 'Low Rated', 'average_rating' => 2.0, 'status' => 'live', 'explicit' => false, 'released_at' => '2020-01-03 00:00:00'],
        ]);

        return $artist;
    }

    /**
     * The logged SQL of the windowed group-limit query (the one carrying `row_number`).
     */
    private function windowQuery(): string
    {
        foreach (DB::getQueryLog() as $entry) {
            $query = \is_string($entry['query'] ?? null) ? $entry['query'] : '';
            if (\str_contains(\strtolower($query), 'row_number')) {
                return $query;
            }
        }

        self::fail('No windowed group-limit query (row_number) was logged.');
    }

    /**
     * The bindings of the windowed group-limit query, stringified for a stable comparison.
     *
     * @return list<string>
     */
    private function windowBindings(): array
    {
        foreach (DB::getQueryLog() as $entry) {
            $query = \is_string($entry['query'] ?? null) ? $entry['query'] : '';
            if (!\str_contains(\strtolower($query), 'row_number')) {
                continue;
            }

            $bindings = \is_array($entry['bindings'] ?? null) ? $entry['bindings'] : [];

            return \array_map(static fn(mixed $b): string => \is_scalar($b) ? (string) $b : '', \array_values($bindings));
        }

        self::fail('No windowed group-limit query (row_number) was logged.');
    }

    /**
     * Lowercases and collapses whitespace so the SQL assertions are robust to formatting.
     */
    private function normalise(string $sql): string
    {
        return (string) \preg_replace('/\s+/', ' ', \strtolower($sql));
    }

    /**
     * The artist's wire id (its primary key coerced to a string), read safely off the model.
     */
    private function key(Artist $artist): string
    {
        /** @var mixed $key */
        $key = $artist->getKey();

        return \is_scalar($key) ? (string) $key : '';
    }

    /**
     * @return list<Artist>
     */
    private function allArtists(): array
    {
        /** @var list<Artist> $artists */
        $artists = Artist::query()->orderBy('id')->get()->all();

        return $artists;
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
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
            self::assertTrue($item instanceof Album || $item instanceof Track);
            /** @var mixed $key */
            $key = $item->getKey();
            $ids[] = \is_scalar($key) ? (string) $key : '';
        }

        return $ids;
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function sortedIds(CollectionResult $result): array
    {
        $ids = $this->ids($result);
        \sort($ids);

        return $ids;
    }
}
