<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-3b Relationship Queries profile acceptance suite (the Laravel analogue of the
 * bundle's relationship-queries + windowed-include conformance, core ADR 0058 / PLAN
 * decision 9): a windowed relationship-linkage endpoint, a windowed related endpoint, and an
 * included relationship windowed from the PRIMARY request via `relatedQuery`/`rQ` under the
 * negotiated profile — every assertion run identically against the in-memory witness
 * ({@see InMemoryRelationshipQueriesConformanceTest}, which windows through core's
 * `WindowExecutor` + the PK tiebreak) and the reference Eloquent provider
 * ({@see EloquentRelationshipQueriesConformanceTest}, the `groupLimit`/`ROW_NUMBER() OVER
 * (PARTITION BY … ORDER BY …, pk)` SQL push-down that ADR 0006 left throwing in 3a).
 *
 * The two implementations referee each other on the same seeded
 * {@see \Workbench\App\Support\ConformanceFixtures} graph: Radiohead (1) owns four albums
 * (1/3/6/7, one of them — `in rainbows` — carrying a NULL `available_from`), and playlist 1
 * owns four ordered tracks (1/2/3/4) three of which (1/2/3) TIE on `released_at` (1997-05-21).
 * So a divergence in tie ordering, NULL placement, per-parent windowing, or count parity
 * surfaces as a between-provider difference on the shared assertions — the referee premise
 * the SQL push-down is held to. This is the suite that makes the PLAN's tie-break watch item
 * a test (byte-identical pages on tied keys).
 */
abstract class RelationshipQueriesConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

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
            \haddowg\JsonApiLaravel\JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- windowed relationship-linkage endpoint --------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theRelationshipEndpointWindowsACountableToManyWithPlainFormPaginationLinks(): void
    {
        // Radiohead(1) owns albums 1/3/6/7; released_at DESC (the requested sort), page size 2
        // → page 1 = in rainbows(6), amnesiac(7). The albums relation is countable(), so the
        // windowed page carries first + next + last (the count-known arm), in the spec's PLAIN
        // form against the relationship endpoint (never the relatedQuery[…] profile form).
        $response = $this->fetch('/api/artists/1/relationships/albums?sort=-releasedAt&page[size]=2');

        $response->assertOk();
        self::assertSame([['type' => 'albums', 'id' => '6'], ['type' => 'albums', 'id' => '7']], $response->json('data'));
        self::assertIsString($response->json('links.first'));
        self::assertIsString($response->json('links.next'));
        self::assertIsString($response->json('links.last'));

        // Page 2 continues the same total order: Kid A(3), OK Computer(1).
        $page2 = $this->fetch('/api/artists/1/relationships/albums?sort=-releasedAt&page[number]=2&page[size]=2');
        $page2->assertOk();
        self::assertSame([['type' => 'albums', 'id' => '3'], ['type' => 'albums', 'id' => '1']], $page2->json('data'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theRelationshipEndpointWindowsANonCountableToManyCountFree(): void
    {
        // The bare `tracks` belongsToMany is NOT countable(): playlist 1 owns four tracks,
        // page size 2 → the count-free arm emits a `next` (a further page exists) driven by the
        // window+1 probe, but NO `last` (no total). Identical on both providers.
        $response = $this->fetch('/api/playlists/1/relationships/tracks?sort=releasedAt&page[size]=2');

        $response->assertOk();
        self::assertCount(2, (array) $response->json('data'));
        self::assertIsString($response->json('links.next'));
        self::assertNull($response->json('links.last'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theRelationshipEndpointRendersTheFullLinkageWithoutQueryParameters(): void
    {
        // No query parameters → the whole association off the loaded parent (the Phase-3a
        // full-linkage contract is preserved; windowing is on-demand only).
        $response = $this->fetch('/api/artists/1/relationships/albums');

        $response->assertOk();
        self::assertCount(4, (array) $response->json('data'));
    }

    // --- windowed related endpoint — tie-break determinism (THE referee) -------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function theRelatedEndpointWindowIsDeterministicOnTiedSortKeys(): void
    {
        // Playlist 1's ordered tracks 1/2/3 all released on 1997-05-21 (a three-way tie on the
        // sort column); track 4 is 2000-10-02. A releasedAt-ASC window of page size 2 must
        // resolve the tie by the appended PK tiebreak — id ASC — so page 1 is [1, 2] and page 2
        // is [3, 4] BYTE-IDENTICALLY on both providers (the SQL `ORDER BY released_at, id`
        // group-limit and the witness `withPkTiebreak` produce the same page — the ADR 0006
        // referee, the PLAN tie-break watch item as a test).
        $page1 = $this->fetch('/api/playlists/1/tracks?sort=releasedAt&page[size]=2');
        $page1->assertOk();
        self::assertSame(['1', '2'], $this->ids($page1));

        $page2 = $this->fetch('/api/playlists/1/tracks?sort=releasedAt&page[number]=2&page[size]=2');
        $page2->assertOk();
        self::assertSame(['3', '4'], $this->ids($page2));
    }

    // --- NULL-ordering parity --------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function nullOrderingUnderAWindowedSortIsIdenticalOnBothProviders(): void
    {
        // Radiohead(1)'s albums by the NULLABLE `available_from`: album 6 (`in rainbows`) has a
        // NULL, the others 1997/2000/2001. A NULL must land in the SAME position on both
        // providers (a divergence here is exactly the null-handling impedance the referee
        // exists to catch): asc → the NULL sorts first [6,1,3,7]; desc reverses → NULL last
        // [7,3,1,6]. Refereed over the windowed related endpoint.
        self::assertSame(['6', '1', '3', '7'], $this->ids($this->fetch('/api/artists/1/albums?sort=availableFrom')));
        self::assertSame(['7', '3', '1', '6'], $this->ids($this->fetch('/api/artists/1/albums?sort=-availableFrom')));
    }

    // --- windowed include (relatedQuery) under the negotiated profile ----------

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortWindowsAnIncludedToManyPerParentAcrossACollection(): void
    {
        // GET /artists?include=albums with a per-relationship sort: each parent's albums linkage
        // is windowed to page 1 ordered by -releasedAt INDEPENDENTLY (the multi-parent SQL
        // push-down / the witness per-parent WindowExecutor). Radiohead(1) → [6,7,3,1];
        // Portishead(2) → [2]; Massive Attack(3) → [4,5] (Mezzanine 1998 before Blue Lines 1991).
        $document = $this->profileDocument('/api/artists?include=albums&relatedQuery[albums][sort]=-releasedAt');

        $expected = ['1' => ['6', '7', '3', '1'], '2' => ['2'], '3' => ['4', '5']];
        $seen = [];
        foreach ($this->collection($document) as $resource) {
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }
            self::assertSame($expected[$id], $this->linkageIds($resource, 'albums'), \sprintf('artist "%s" albums windowed per parent', $id));
            $seen[$id] = true;
        }

        // Guard against a vacuous pass: assert every expected parent was actually seen + asserted
        // (a drifted key / empty data would otherwise pass on assertOk() alone).
        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected artist was windowed');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterWindowsAToManyIncludeOnBothProviders(): void
    {
        // Radiohead(1) owns albums 1/3/6/7; album 7 is a `draft`. A relatedQuery FILTER on the
        // windowed include keeps only `released` albums, ordered -releasedAt → [6,3,1] (7 dropped)
        // — the batcher's filter arm (windowRelationOverPage's push-down through
        // fetchRelatedCollectionBatch), refereed dual-provider (SQL push-down vs WindowExecutor).
        $document = $this->profileDocument('/api/artists/1?include=albums&relatedQuery[albums][sort]=-releasedAt&relatedQuery[albums][filter][status]=released');

        self::assertSame(['6', '3', '1'], $this->linkageIds($this->primaryResource($document), 'albums'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function relatedQueryFilterNullsAnExcludedToOneOnBothProviders(): void
    {
        // Album 1's artist is Radiohead (1, track_count 3). A MATCHING relatedQuery[artist][filter]
        // (minTracks 1 <= 3) keeps the linkage + the include; a non-matching one (minTracks 5 > 3)
        // nulls the to-one linkage AND omits the include (core ADR 0068). Refereed on both
        // providers — the Eloquent batcher nulls the relation through setRelation (never the
        // attribute bag), so the render is null without poisoning the model.
        //
        // The matching (non-nulling) case is asserted FIRST: on the in-memory witness the store
        // holds ONE shared album instance across the test's HTTP calls, so the excluded case's
        // property-null would otherwise persist into a later read (the reference provider
        // re-fetches a fresh model per request, so it does not) — the matching read mutates
        // nothing, so its ordering is provider-agnostic.
        $kept = $this->profileDocument('/api/albums/1?include=artist&relatedQuery[artist][filter][minTracks]=1');
        self::assertSame(['type' => 'artists', 'id' => '1'], $this->relationshipObject($this->primaryResource($kept), 'artist')['data'] ?? null);

        $excluded = $this->profileDocument('/api/albums/1?include=artist&relatedQuery[artist][filter][minTracks]=5');
        $artist = $this->relationshipObject($this->primaryResource($excluded), 'artist');
        self::assertArrayHasKey('data', $artist);
        self::assertNull($artist['data']);
        self::assertEmpty($excluded['included'] ?? []);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function aWindowedIncludeIsDeterministicOnTiedSortKeys(): void
    {
        // The tie-break referee on the include path: playlist 1's ordered tracks 1/2/3 tie on
        // released_at (1997), track 4 is 2000. Windowed by -releasedAt, track 4 leads and the
        // tied trio follows resolved by id ASC → [4,1,2,3]; the ASC window is [1,2,3,4]. Both
        // orders are BYTE-IDENTICAL on the SQL push-down and the witness (ADR 0006).
        $desc = $this->profileDocument('/api/playlists/1?include=orderedTracks&relatedQuery[orderedTracks][sort]=-releasedAt');
        self::assertSame(['4', '1', '2', '3'], $this->linkageIds($this->primaryResource($desc), 'orderedTracks'));

        $asc = $this->profileDocument('/api/playlists/1?include=orderedTracks&relatedQuery[orderedTracks][sort]=releasedAt');
        self::assertSame(['1', '2', '3', '4'], $this->linkageIds($this->primaryResource($asc), 'orderedTracks'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function theRqShorthandIsIdenticalToTheCanonicalFamily(): void
    {
        $canonical = $this->profileDocument('/api/playlists/1?include=orderedTracks&relatedQuery[orderedTracks][sort]=-releasedAt');
        $shorthand = $this->profileDocument('/api/playlists/1?include=orderedTracks&rQ[orderedTracks][sort]=-releasedAt');

        self::assertSame(
            $this->linkageIds($this->primaryResource($canonical), 'orderedTracks'),
            $this->linkageIds($this->primaryResource($shorthand), 'orderedTracks'),
        );
        self::assertSame(['4', '1', '2', '3'], $this->linkageIds($this->primaryResource($shorthand), 'orderedTracks'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aPlainIncludeRendersTheFullLinkageWithoutTheProfile(): void
    {
        // A plain `?include=albums` (no profile, no relatedQuery) renders the whole membership
        // — windowing fires only under the negotiated profile + a relatedQuery.
        $response = $this->fetch('/api/artists/1?include=albums');

        $response->assertOk();
        self::assertCount(4, (array) $response->json('data.relationships.albums.data'));
    }

    // --- count parity ----------------------------------------------------------

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching')]
    public function aWindowedIncludeCountsPerParentIdenticallyUnderWithCount(): void
    {
        // Under BOTH the Relationship-Queries and Countable profiles, `?withCount=albums` on a
        // windowed collection include reports each parent's REAL total via meta.total,
        // independent of the page size — identical per-parent totals on both providers
        // (Radiohead 4, Portishead 1, Massive Attack 2, the three album-less artists 0).
        $document = $this->countingProfileDocument('/api/artists?include=albums&withCount=albums&relatedQuery[albums][sort]=-releasedAt');

        $expected = ['1' => 4, '2' => 1, '3' => 2, '4' => 0, '5' => 0, '6' => 0];
        $seen = [];
        foreach ($this->collection($document) as $resource) {
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !\array_key_exists($id, $expected)) {
                continue;
            }
            self::assertSame($expected[$id], $this->relationshipTotal($resource, 'albums'), \sprintf('artist "%s" albums total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected artist reported a per-parent total');
    }

    // --- profile negotiation + the error family --------------------------------

    #[Test]
    #[Group('spec:profiles')]
    public function theResponseAdvertisesTheNegotiatedProfile(): void
    {
        $response = $this->fetchWithProfile('/api/artists/1?include=albums&relatedQuery[albums][sort]=-releasedAt');
        $response->assertOk();

        self::assertContains(self::RELATIONSHIP_QUERIES_PROFILE, (array) $response->json('jsonapi.profile'));
        self::assertStringContainsString('profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function theRelatedQueryFamilyIsRejectedWhenTheProfileIsNotNegotiated(): void
    {
        // The relatedQuery/rQ family is a profile keyword: recognized only when the client
        // negotiated the profile. Without it, strict query-parameter validation rejects the
        // unrecognized top-level family with a 400 keyed on the family base name.
        $response = $this->fetch('/api/artists/1?include=albums&relatedQuery[albums][sort]=-releasedAt');

        $response->assertStatus(400);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame(['parameter' => 'relatedQuery'], $response->json('errors.0.source'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownSortKeyUnderTheProfileIs400(): void
    {
        $response = $this->fetchWithProfile('/api/artists/1?relatedQuery[albums][sort]=nope');

        $response->assertStatus(400);
        self::assertSame('SORTING_UNRECOGNIZED', $response->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function aToOneRelationshipPathWithSortUnderTheProfileIs400(): void
    {
        // `artist` is a to-one (BelongsTo): addressing it with a [sort] op is a 400 (a single
        // member has nothing to order), with the offending canonical profile param in
        // source.parameter (core ADR 0068).
        $response = $this->fetchWithProfile('/api/albums/1?relatedQuery[artist][sort]=name');

        $response->assertStatus(400);
        self::assertSame(['parameter' => 'relatedQuery[artist]'], $response->json('errors.0.source'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownRelatedQueryPathUnderTheProfileIs400(): void
    {
        // A relatedQuery path that resolves to no relation of the primary type is a 400 keyed on
        // the offending profile param — the path-validation gate the batcher runs before it
        // windows (never a silent ignore of an unaddressable path).
        $response = $this->fetchWithProfile('/api/artists/1?relatedQuery[nonsense][sort]=name');

        $response->assertStatus(400);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame(['parameter' => 'relatedQuery[nonsense]'], $response->json('errors.0.source'));
    }

    // --- relationship-endpoint query params it cannot honour are 400, not ignored --

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function aQueriedPivotRelationshipEndpointIsRejectedRatherThanSilentlyIgnored(): void
    {
        // `orderedTracks` is a pivot belongsToMany; its windowed linkage endpoint (bundle ADR
        // 0096) is a deferred tail (docs/adr/0010), so a `?sort` on it is a 400 rather than a
        // silent full-set render (a client believing its sort applied is the worst outcome). A
        // plain read (no params) still renders the full linkage — asserted elsewhere.
        $this->fetch('/api/playlists/1/relationships/orderedTracks?sort=releasedAt')->assertStatus(400);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function aQueriedToOneRelationshipEndpointIsRejectedRatherThanSilentlyIgnored(): void
    {
        // A to-one (`artist`) relationship endpoint has no collection to sort/page and its
        // filter-nulling on this surface is deferred (docs/adr/0010), so a query parameter is a
        // 400 rather than silently ignored.
        $this->fetch('/api/albums/1/relationships/artist?filter[nameContains]=Radiohead')->assertStatus(400);
    }

    // --- helpers ---------------------------------------------------------------

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
    protected function fetchWithProfile(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function profileDocument(string $uri): array
    {
        $response = $this->fetchWithProfile($uri);
        $response->assertOk();

        /** @var array<string, mixed> $document */
        $document = $response->json();

        return $document;
    }

    /**
     * Fetches under BOTH the Relationship-Queries and Countable profiles.
     *
     * @return array<string, mixed>
     */
    protected function countingProfileDocument(string $uri): array
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . ' ' . self::COUNTABLE_PROFILE . '"']);
        $response->assertOk();

        /** @var array<string, mixed> $document */
        $document = $response->json();

        return $document;
    }

    /**
     * The primary-collection resources of a document.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    protected function collection(array $document): array
    {
        $data = $document['data'] ?? [];
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * The single primary resource object of a document (`data`).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function primaryResource(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The `meta.total` of a resource object's named relationship (the Countable-profile count).
     *
     * @param array<string, mixed> $resource
     */
    protected function relationshipTotal(array $resource, string $relationship): mixed
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject);

        $meta = $relationshipObject['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta['total'] ?? null;
    }

    /**
     * The relationship object (`{data?, links?, meta?}`) of a resource's named relationship.
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    protected function relationshipObject(array $resource, string $relationship): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $object */
        return $object;
    }

    /**
     * The `data.*.id` list of a to-many document, in rendered order.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function ids(TestResponse $response): array
    {
        /** @var list<array{id: string}> $data */
        $data = $response->json('data');

        return \array_map(static fn(array $row): string => $row['id'], $data);
    }

    /**
     * The linkage `data` ids of a resource object's named relationship, in rendered order.
     *
     * @param array<string, mixed> $resource
     *
     * @return list<string>
     */
    protected function linkageIds(array $resource, string $relationship): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        $data = $relationshipObject['data'] ?? null;
        self::assertIsArray($data, \sprintf('relationship "%s" carries linkage data', $relationship));

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
