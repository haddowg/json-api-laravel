<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Filter\GreaterThanOrEqual;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\JsonApi\AlbumResource;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * The reference Eloquent provider's batched relation seams, executed as real SQL over
 * SQLite (PLAN decision 8). Seeds the shared {@see \Workbench\App\Support\ConformanceFixtures}
 * (Radiohead owns four albums, Portishead one, Massive Attack two, three artists none — the
 * 0/1/many batch edge cases), then drives {@see EloquentDataProvider}'s relation methods
 * directly: the eager-pipeline fast path (dictionary partition + `setRelation` write-back),
 * the to-one FK projection, the grouped zero-filled count, the to-one filter probe, the
 * load-state seam, and the Phase-3b windowed-batch guard.
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
#[CoversClass(EloquentRelationshipLoadState::class)]
final class EloquentRelationBatchTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    // --- the ?include fast path (addEagerConstraints + getEager + match) -------

    #[Test]
    public function itBatchesAToManyIntoAWireKeyedPartitionOfZeroOneAndManyChildren(): void
    {
        $artists = $this->allArtists();

        $batch = $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $artists,
            HasMany::make('albums', 'albums'),
            $this->emptyCriteria(),
            $this->request(),
        );

        // Many: Radiohead (1) owns albums 1/3/6/7.
        self::assertSame(['1', '3', '6', '7'], $this->sortedIds($batch->for('1')));
        // One: Portishead (2) owns album 2 (Dummy).
        self::assertSame(['2'], $this->sortedIds($batch->for('2')));
        // Many: Massive Attack (3) owns albums 4/5.
        self::assertSame(['4', '5'], $this->sortedIds($batch->for('3')));
        // Zero: artists 4/5/6 own none — an absent parent fills with an empty result.
        self::assertSame([], $this->sortedIds($batch->for('4')));
        self::assertSame([], $this->sortedIds($batch->for('5')));
        self::assertSame([], $this->sortedIds($batch->for('6')));
    }

    #[Test]
    public function itBatchesAToManyInOneQuery(): void
    {
        $artists = $this->allArtists();
        $provider = $this->provider();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $provider->fetchRelatedCollectionBatch(
            'artists',
            $artists,
            HasMany::make('albums', 'albums'),
            $this->emptyCriteria(),
            $this->request(),
        );

        // One eager `where artist_id in (…)` for the whole page — never one query per parent.
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    // --- the to-one arm (BelongsTo FK projection through the same pipeline) ----

    #[Test]
    public function itBatchesAToOneAsAZeroOrOneProjectionIncludingANullOwner(): void
    {
        // A null-owner album exercises the empty to-one partition (the seed data has none).
        Album::query()->create([
            'id' => 99,
            'artist_id' => null,
            'title' => 'Orphan',
            'status' => 'released',
            'explicit' => false,
            'released_at' => new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        ]);

        $albums = Album::query()->orderBy('id')->get()->all();

        $batch = $this->provider()->fetchRelatedCollectionBatch(
            'albums',
            \array_values($albums),
            BelongsTo::make('artist', 'artists'),
            $this->emptyCriteria(),
            $this->request(),
        );

        // Owned albums project their single artist; the orphan projects nothing.
        self::assertSame(['1'], $this->sortedIds($batch->for('1')));
        self::assertSame(['2'], $this->sortedIds($batch->for('2')));
        self::assertSame(['3'], $this->sortedIds($batch->for('4')));
        self::assertSame([], $this->sortedIds($batch->for('99')));
    }

    // --- load-state (setRelation write-back → relationLoaded) -----------------

    #[Test]
    public function theBatchWriteBackMakesTheRelationLoadedSoNoReFetchFires(): void
    {
        $artists = $this->allArtists();
        $loadState = new EloquentRelationshipLoadState();
        $relation = HasMany::make('albums', 'albums');

        // Freshly fetched: the relation is not loaded (a read would query).
        self::assertFalse($artists[0]->relationLoaded('albums'));
        self::assertFalse($loadState->isRelationshipLoaded($artists[0], $relation));

        $this->provider()->fetchRelatedCollectionBatch('artists', $artists, $relation, $this->emptyCriteria(), $this->request());

        // The batch's match() setRelation'd every parent, so the load-state seam reports
        // loaded and reading the relation triggers no further query.
        self::assertTrue($artists[0]->relationLoaded('albums'));
        self::assertTrue($loadState->isRelationshipLoaded($artists[0], $relation));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $albums = $artists[0]->getRelation('albums');
        self::assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();

        self::assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $albums);
        self::assertCount(4, $albums);
    }

    // --- countRelated (grouped withCount, zero-filled) ------------------------

    #[Test]
    public function itCountsEachParentsRelationZeroFillingTheEmptyOnes(): void
    {
        $counts = $this->provider()->countRelated(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums'),
            $this->emptyCriteria(),
            $this->request(),
        );

        self::assertSame(['1' => 4, '2' => 1, '3' => 2, '4' => 0, '5' => 0, '6' => 0], $counts);
    }

    #[Test]
    public function itCountsInOneQuery(): void
    {
        $artists = $this->allArtists();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->provider()->countRelated(
            'artists',
            $artists,
            HasMany::make('albums', 'albums'),
            $this->emptyCriteria(),
            $this->request(),
        );

        // One grouped correlated-subquery COUNT for the whole page (plus the parents fetch).
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function theCountPushesTheCriteriaFilterIntoTheSubquery(): void
    {
        // Radiohead's albums: 1/3/6 are `released`, 7 is `draft` → three released.
        $counts = $this->provider()->countRelated(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums'),
            new CollectionCriteria(
                new QueryParameters([], [], [], ['status' => 'released'], []),
                [WhereIn::make('status')],
            ),
            $this->request(),
        );

        self::assertSame(3, $counts['1']);
        self::assertSame(1, $counts['2']);
        // Massive Attack's albums 4/5 are both `archived` → zero released.
        self::assertSame(0, $counts['3']);
    }

    // --- the filtered to-one probe --------------------------------------------

    #[Test]
    public function relatedToOneMatchesProbesTheSingleTargetAgainstTheFilter(): void
    {
        $radiohead = Artist::query()->findOrFail(1);
        $relation = BelongsTo::make('artist', 'artists');

        // Radiohead has track_count 3, so minTracks>=5 excludes it, minTracks>=1 keeps it.
        self::assertFalse($this->provider()->relatedToOneMatches(
            'artists',
            $radiohead,
            $relation,
            $this->filterCriteria(['minTracks' => 5]),
            $this->request(),
        ));
        self::assertTrue($this->provider()->relatedToOneMatches(
            'artists',
            $radiohead,
            $relation,
            $this->filterCriteria(['minTracks' => 1]),
            $this->request(),
        ));
    }

    #[Test]
    public function relatedToOneMatchesBatchIntersectsTheDistinctTargetsInOneProbe(): void
    {
        $albums = Album::query()->orderBy('id')->get()->all();

        // Owners' track_count: Radiohead(1)=3, Portishead(2)=2, Massive Attack(3)=5.
        // minTracks>=5 keeps only Massive Attack, so only its albums (4/5) match.
        $matches = $this->provider()->relatedToOneMatchesBatch(
            'albums',
            \array_values($albums),
            BelongsTo::make('artist', 'artists'),
            $this->filterCriteria(['minTracks' => 5]),
            $this->request(),
        );

        self::assertSame(
            ['1' => false, '2' => false, '3' => false, '4' => true, '5' => true, '6' => false, '7' => false],
            $matches,
        );
    }

    // --- the Phase-3b windowed-batch guard (no PHP-window fallback) ------------

    #[Test]
    public function aWindowedBatchThrowsRatherThanWindowingInPhp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Phase 3b');

        $this->provider()->fetchRelatedCollectionBatch(
            'artists',
            $this->allArtists(),
            HasMany::make('albums', 'albums'),
            new CollectionCriteria($this->query(), window: new OffsetWindow(0, 2)),
            $this->request(),
        );
    }

    // --- the single-parent related endpoint (scoped + windowed) ---------------

    #[Test]
    public function fetchRelatedCollectionScopesToTheParentAndWindows(): void
    {
        $radiohead = Artist::query()->findOrFail(1);
        $albumResource = new AlbumResource();

        // released_at DESC (the albums resource default): in rainbows(6), amnesiac(7),
        // Kid A(3), OK Computer(1). Windowed to the first two, counted.
        $result = $this->provider()->fetchRelatedCollection(
            'albums',
            $radiohead,
            HasMany::make('albums', 'albums'),
            new CollectionCriteria(
                new QueryParameters([], [], [], [], []),
                sorts: $albumResource->allSorts(),
                window: new OffsetWindow(0, 2),
                defaultSort: $albumResource->defaultSort(),
                wantsCount: true,
            ),
            $this->request(),
        );

        self::assertSame(4, $result->total);
        self::assertSame(['6', '7'], $this->ids($result));
    }

    private function provider(): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'artists' => Artist::class,
            'albums' => Album::class,
        ]);
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

    private function emptyCriteria(): CollectionCriteria
    {
        return new CollectionCriteria($this->query());
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filterCriteria(array $filter): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], [], $filter, []),
            [GreaterThanOrEqual::make('minTracks', 'track_count')],
        );
    }

    private function query(): QueryParameters
    {
        return new QueryParameters([], [], [], [], []);
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }

    /**
     * The wire ids of a batch partition, sorted for an order-independent membership check.
     *
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

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertTrue($item instanceof Album || $item instanceof Artist);
            /** @var mixed $key */
            $key = $item->getKey();
            $ids[] = \is_scalar($key) ? (string) $key : '';
        }

        return $ids;
    }
}
