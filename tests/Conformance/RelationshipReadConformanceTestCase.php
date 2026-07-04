<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-3a relationship-READ acceptance suite (the Laravel analogue of the bundle's
 * relationship-read conformance): the related (`GET /{type}/{id}/{rel}`) and
 * relationship-linkage (`GET …/relationships/{rel}`) endpoints, compound `?include`
 * (single, collection, nested/deep with dedup), `?withCount` (single + across a
 * collection) and the batch-window edges (a parent with 0 / 1 / many children).
 *
 * Like {@see ReadConformanceTestCase} it is **abstract over the provider wiring** so the
 * SAME assertions run against the in-memory witness ({@see InMemoryRelationshipReadConformanceTest})
 * and the reference Eloquent provider ({@see EloquentRelationshipReadConformanceTest}),
 * both over the SAME {@see \Workbench\App\Support\ConformanceFixtures} object graph
 * (Radiohead owns four albums, Portishead one, Massive Attack two, three artists none):
 * a divergent result localizes to one provider's execution — the referee premise the
 * SQL push-down is held to (PLAN decision 9). The witness runs core's `WindowExecutor`
 * with the same PK tiebreak the SQL path pins, so this suite referees the window on
 * every run.
 *
 * Provider-asymmetric behaviour is asserted in the concretes, NOT here, because it is not
 * identical by design: the reference provider wires an {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState}
 * so a lazy to-many renders **links-only** until it is `?include`d (the N+1-avoidance
 * seam), while the witness injects no load-state predicate and renders every relation's
 * linkage eagerly (the standalone default). The N+1 query-count guard is likewise
 * Eloquent-only (the witness issues no SQL).
 *
 * Polymorphic (morphTo / morphToMany) reads and the belongsToMany `meta.pivot` READ are
 * exercised over their own isolated blog fixtures in
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\InMemoryPolymorphicRelationshipTest} /
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\EloquentPolymorphicRelationshipTest}, so the
 * heterogeneous-morph surface stays dual-provider without a morph map in the music-catalog
 * dataset.
 */
abstract class RelationshipReadConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    /**
     * The workbench service provider that wires exactly ONE provider (in-memory or
     * Eloquent) over the shared resources, seeded from the same fixtures.
     *
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * Seeds the concrete's data layer. The in-memory concrete no-ops (the fixtures live
     * in the provider registration); the Eloquent concrete migrates + seeds.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- the related to-many collection --------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedToManyRendersTheCollectionSortedAndCounted(): void
    {
        // Radiohead (1) owns four albums; the related endpoint applies the albums
        // resource's own default sort (released_at DESC) and its counting paginator.
        $response = $this->fetch('/api/artists/1/albums');

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonCount(4, 'data');
        self::assertSame('albums', $response->json('data.0.type'));
        // released_at DESC: in rainbows (2007), amnesiac (2001), Kid A (2000), OK Computer (1997).
        self::assertSame(['6', '7', '3', '1'], $this->ids($response));
        // The relation is countable(), so the counted page carries the total.
        self::assertSame(4, $response->json('meta.total'));
        self::assertSame(4, $response->json('meta.page.total'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedToManyOfAParentWithNoChildrenIsEmpty(): void
    {
        // aphex twin (4) owns no albums — the empty batch partition / empty collection.
        $response = $this->fetch('/api/artists/4/albums');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        self::assertSame(0, $response->json('meta.total'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedToManySingleton(): void
    {
        // Portishead (2) owns exactly one album (Dummy) — the singleton partition.
        $response = $this->fetch('/api/artists/2/albums');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        self::assertSame('2', $response->json('data.0.id'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theRelatedToManyPaginates(): void
    {
        $response = $this->fetch('/api/artists/1/albums?page[size]=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        self::assertSame(['6', '7'], $this->ids($response));
        self::assertSame(4, $response->json('meta.page.total'));
        self::assertIsString($response->json('links.next'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function theRelatedToManyAcceptsAScopedSort(): void
    {
        // The related endpoint resolves the albums resource's own sort vocabulary; an
        // explicit ?sort overrides the default DESC order.
        $response = $this->fetch('/api/artists/1/albums?sort=releasedAt');

        $response->assertOk();
        self::assertSame(['1', '3', '7', '6'], $this->ids($response));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theRelatedToManyAcceptsAScopedFilter(): void
    {
        // The related endpoint resolves the albums resource's own filter vocabulary; a
        // status filter narrows Radiohead's four albums to the three `released` ones
        // (still under the albums released_at DESC default sort).
        $response = $this->fetch('/api/artists/1/albums?filter[status]=released');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        self::assertSame(['6', '3', '1'], $this->ids($response));
    }

    // --- the related to-one ---------------------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedToOneRendersTheSingleRelatedResource(): void
    {
        $response = $this->fetch('/api/albums/1/artist');

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        self::assertSame('artists', $response->json('data.type'));
        self::assertSame('1', $response->json('data.id'));
        self::assertSame('Radiohead', $response->json('data.attributes.name'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFilterOnTheRelatedToOneNullsANonMatchingOwner(): void
    {
        // The monomorphic to-one honours a scoped filter drawn from the related resource's
        // vocabulary: album 1's artist is Radiohead (track_count 3). A `minTracks` bound the
        // owner fails nulls the to-one (renders `data: null`) — the to-one twin of the
        // filtered to-many collection — while a bound it satisfies renders the owner
        // unchanged. Refereed on BOTH providers (the SQL existence probe against the witness
        // predicate), so an in-memory/SQL divergence in the filtered to-one shows up here.
        $excluded = $this->fetch('/api/albums/1/artist?filter[minTracks]=5');
        $excluded->assertOk();
        self::assertNull($excluded->json('data'));

        $included = $this->fetch('/api/albums/1/artist?filter[minTracks]=1');
        $included->assertOk();
        self::assertSame('artists', $included->json('data.type'));
        self::assertSame('1', $included->json('data.id'));
    }

    // --- the relationship-linkage endpoint ------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function theRelationshipEndpointRendersToManyLinkageOnly(): void
    {
        $response = $this->fetch('/api/artists/1/relationships/albums');

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        // Linkage only — resource identifiers, no attributes.
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertCount(4, $data);
        foreach ($data as $identifier) {
            self::assertSame(['type', 'id'], array_keys($identifier));
            self::assertSame('albums', $identifier['type']);
        }
        // Membership (linkage renders the raw related set, not the collection sort).
        self::assertSame(['1', '3', '6', '7'], $this->sortedLinkageIds($data));
        // The convention self/related links are present.
        self::assertIsString($response->json('links.self'));
        self::assertIsString($response->json('links.related'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelationshipEndpointRendersToOneLinkage(): void
    {
        $response = $this->fetch('/api/albums/1/relationships/artist');

        $response->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '1'], $response->json('data'));
    }

    // --- compound documents via ?include -------------------------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includeExpandsAToManyIntoTheCompoundDocument(): void
    {
        $response = $this->fetch('/api/artists/1?include=albums');

        $response->assertOk();
        // The primary resource's relationship carries linkage data.
        self::assertSame('1', $response->json('data.id'));
        $linkage = $response->json('data.relationships.albums.data');
        self::assertIsArray($linkage);
        self::assertCount(4, $linkage);
        // The four albums are expanded into `included`.
        $included = $response->json('included');
        self::assertIsArray($included);
        self::assertCount(4, $included);
        $types = array_unique(array_column($included, 'type'));
        self::assertSame(['albums'], array_values($types));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includeOfAToOneExpandsTheOwner(): void
    {
        $response = $this->fetch('/api/albums/1?include=artist');

        $response->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '1'], $response->json('data.relationships.artist.data'));
        self::assertSame('artists', $response->json('included.0.type'));
        self::assertSame('1', $response->json('included.0.id'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNestedIncludeExpandsBothLevelsAndDedupesThePrimary(): void
    {
        // ?include=artist.albums (depth 2, within the max_include_depth of 3): album 1 →
        // its artist → that artist's albums. The primary album 1 is NOT repeated in
        // `included` (compound-document dedup), so included = artist 1 + albums 3/6/7.
        $response = $this->fetch('/api/albums/1?include=artist.albums');

        $response->assertOk();
        $included = $response->json('included');
        self::assertIsArray($included);

        $artists = $this->includedIds($included, 'artists');
        $albums = $this->includedIds($included, 'albums');
        self::assertSame(['1'], $artists);
        self::assertSame(['3', '6', '7'], $albums);
        // The primary resource never reappears in `included`.
        self::assertNotContains('1', $albums);
    }

    // --- ?withCount (Countable profile) --------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function withCountEmitsMetaTotalOnACountableRelationship(): void
    {
        // ?withCount is gated on the Countable profile being negotiated in Accept.
        $response = $this->fetchWithCount('/api/artists/1?withCount=albums');

        $response->assertOk();
        self::assertSame(4, $response->json('data.relationships.albums.meta.total'));

        // A parent with no children reports 0 (zero-fill).
        $empty = $this->fetchWithCount('/api/artists/4?withCount=albums');
        $empty->assertOk();
        self::assertSame(0, $empty->json('data.relationships.albums.meta.total'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function withCountOnATheRelatedToOneCountsTheTargetsOwnRelationship(): void
    {
        // `?withCount` named against a to-one related endpoint counts a relationship on the
        // rendered TARGET (album 1's artist is Radiohead, who owns four albums) — parity with
        // the single-resource fetch, installed per-request in the to-one fetchRelated arm (so
        // it is present here and, with the per-dispatch clear, never a stale cross-request
        // count). Refereed on both providers.
        $response = $this->fetchWithCount('/api/albums/1/artist?withCount=albums');

        $response->assertOk();
        self::assertSame(4, $response->json('data.relationships.albums.meta.total'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function withCountIsBatchedAcrossACollection(): void
    {
        $response = $this->fetchWithCount('/api/artists?withCount=albums');

        $response->assertOk();
        // The per-parent totals are keyed to each artist (0/1/many edges in one grouped
        // count): Radiohead 4, Portishead 1, Massive Attack 2, aphex twin 0.
        $byId = [];
        /** @var list<array{id: string, relationships: array{albums: array{meta: array{total: int}}}}> $data */
        $data = $response->json('data');
        foreach ($data as $artist) {
            $byId[$artist['id']] = $artist['relationships']['albums']['meta']['total'];
        }
        self::assertSame(4, $byId['1'] ?? null);
        self::assertSame(1, $byId['2'] ?? null);
        self::assertSame(2, $byId['3'] ?? null);
        self::assertSame(0, $byId['4'] ?? null);
        self::assertSame(0, $byId['5'] ?? null);
        self::assertSame(0, $byId['6'] ?? null);
    }

    // --- the 404 arms ---------------------------------------------------------

    #[Test]
    #[Group('spec:errors')]
    public function theRelatedEndpoint404sWhenTheParentIsMissing(): void
    {
        $this->fetch('/api/artists/9999/albums')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:errors')]
    public function theRelatedEndpoint404sOnAnUnknownRelation(): void
    {
        $response = $this->fetch('/api/artists/1/nonsense');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
    }

    #[Test]
    #[Group('spec:errors')]
    public function theRelationshipEndpoint404sOnAnUnknownRelation(): void
    {
        $this->fetch('/api/artists/1/relationships/nonsense')->assertStatus(404);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function fetchWithCount(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"']);
    }

    /**
     * The `data.*.id` list of a related-collection document, in rendered order.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function ids(TestResponse $response): array
    {
        /** @var list<array{id: string}> $data */
        $data = $response->json('data');

        return array_map(static fn(array $row): string => $row['id'], $data);
    }

    /**
     * The linkage identifier ids, sorted, for a membership (order-independent) assertion.
     *
     * @param list<array{id: string}> $data
     *
     * @return list<string>
     */
    protected function sortedLinkageIds(array $data): array
    {
        $ids = array_map(static fn(array $row): string => $row['id'], $data);
        sort($ids);

        return $ids;
    }

    /**
     * The numerically-sorted ids of the `included` resources of `$type`.
     *
     * @param array<mixed> $included
     *
     * @return list<string>
     */
    protected function includedIds(array $included, string $type): array
    {
        $ids = [];
        foreach ($included as $resource) {
            if (!\is_array($resource)) {
                continue;
            }
            if (($resource['type'] ?? null) === $type) {
                $id = $resource['id'] ?? null;
                self::assertIsString($id);
                $ids[] = $id;
            }
        }
        sort($ids, \SORT_NUMERIC);

        return $ids;
    }
}
