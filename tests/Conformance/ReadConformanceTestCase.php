<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-1 read acceptance suite (the Laravel port of the bundle's
 * `ReadQueryConformanceTestCase` + `ConvenienceFilterConformanceTestCase`):
 * filtering, sorting, pagination, sparse fieldsets, single-resource reads and the
 * query-parameter 400s, asserted as spec-compliant JSON:API documents over HTTP.
 *
 * It is **abstract over the provider wiring** so the SAME assertions run against the
 * in-memory witness ({@see InMemoryReadConformanceTest}) and the reference Eloquent
 * provider ({@see EloquentReadConformanceTest}); both serve the SAME `app/JsonApi`
 * resource declarations over the SAME {@see \Workbench\App\Support\ConformanceFixtures}
 * rows, so a test failing on one provider and not the other localizes the bug to that
 * provider's execution — the point of the referee (PLAN decision 9).
 *
 * The two collections cover the two pagination arms: `artists` is **count-free**
 * (the server-default paginator) so its page assertions prove `hasMore`-driven `next`
 * with no total/`last`; `albums` is **counted** (`PagePaginator::withCount()`) so its
 * assertions prove `meta.page.total`/`lastPage`/`last` and the full nav-link set. The
 * seed data is edge-heavy on purpose (null attributes, repeated sort keys broken by a
 * unique secondary, mixed-case strings, numeric/string/date/bool types).
 *
 * Relationship-scope behaviour (`?include` compound documents, related/relationship
 * endpoints, relationship-existence filters `WhereHas`/`WhereDoesntHave`/`WhereThrough`)
 * landed in Phase 3a and is asserted here dual-provider; the fuller relationship-read
 * matrix (batch-window edges, load-state, `?withCount`, linkage shapes) lives in the
 * dedicated {@see RelationshipReadConformanceTestCase}. The client-driven `?withCount=_self_`
 * opt-in is now recognized (the Countable profile is wired) and asserted here as core's `400`
 * safe-default until a resource opts in via CountableSelfInterface. The null-in-comparison
 * divergence ADR 0003 once deferred is now RESOLVED (core ADR 0116) and asserted directly in
 * {@see orderedComparisonAndRangeOverANullableColumnExcludeNullsIdentically}.
 */
abstract class ReadConformanceTestCase extends Orchestra
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
     * Seeds the concrete's data layer. The in-memory concrete no-ops (the fixtures
     * live in the provider registration); the Eloquent concrete migrates + seeds.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- filtering -----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aContainsFilterMatchesASubstringCaseInsensitively(): void
    {
        // `like`: ASCII case-insensitive substring — identical on both providers
        // (in-memory stripos, Eloquent a folded LIKE).
        self::assertSame(['4'], $this->ids($this->fetch('/api/artists?filter[nameContains]=aphex'), 'artists'));
        self::assertSame(['4'], $this->ids($this->fetch('/api/artists?filter[nameContains]=APHEX'), 'artists'));
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[nameContains]=zzz'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aStartsWithFilterMatchesAPrefixCaseInsensitively(): void
    {
        // `starts`: prefix match (in-memory stripos===0, Eloquent LIKE 'v%').
        self::assertSame(['5'], $this->ids($this->fetch('/api/artists?filter[nameStarts]=boards'), 'artists'));
        self::assertSame(['5'], $this->ids($this->fetch('/api/artists?filter[nameStarts]=BOARDS'), 'artists'));
        // A substring that is not a prefix does not match (distinguishes starts from contains).
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[nameStarts]=head'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anEndsWithFilterMatchesASuffixCaseInsensitively(): void
    {
        // `ends`: suffix match — "Radiohead"/"Portishead" both end "head".
        self::assertSame(['1', '2'], $this->sortedIds('/api/artists?filter[nameEnds]=head', 'artists'));
        self::assertSame(['1', '2'], $this->sortedIds('/api/artists?filter[nameEnds]=HEAD', 'artists'));
        // A prefix is not a suffix (distinguishes ends from starts).
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[nameEnds]=radio'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theStringStrategiesAlsoApplyToAlbums(): void
    {
        self::assertSame(['6'], $this->ids($this->fetch('/api/albums?filter[title]=rainbows'), 'albums'));
        self::assertSame(['6'], $this->ids($this->fetch('/api/albums?filter[titleStarts]=IN'), 'albums'));
        self::assertSame(['3'], $this->ids($this->fetch('/api/albums?filter[titleEnds]=a'), 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filteringByAnIdSetNarrowsAndExcludes(): void
    {
        self::assertSame(['1', '3', '5'], $this->sortedIds('/api/artists?filter[id]=1,3,5', 'artists'));
        self::assertSame(['2', '4', '6'], $this->sortedIds('/api/artists?filter[idNot]=1,3,5', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function setMembershipAndItsNegationSelectIdentically(): void
    {
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->sortedIds('/api/albums?filter[status]=released,archived', 'albums'));
        self::assertSame(['4', '5', '7'], $this->sortedIds('/api/albums?filter[statusNot]=released', 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function nullPresenceFiltersSelectIdentically(): void
    {
        // The request value is ignored — presence on the nullable column decides.
        self::assertSame(['2', '4', '6'], $this->sortedIds('/api/artists?filter[noWebsite]=1', 'artists'));
        self::assertSame(['1', '3', '5'], $this->sortedIds('/api/artists?filter[hasWebsite]=1', 'artists'));
        self::assertSame(['4', '7'], $this->sortedIds('/api/albums?filter[unrated]=1', 'albums'));
        self::assertSame(['1', '2', '3', '5', '6'], $this->sortedIds('/api/albums?filter[rated]=1', 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aNumericComparisonFilterSelectsIdentically(): void
    {
        // `track_count >= 4` (the value is numeric-coerced before binding): ids 3 (5) and 5 (4).
        self::assertSame(['3', '5'], $this->sortedIds('/api/artists?filter[minTracks]=4', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aNumericRangeAppliesAnInclusiveMinAndMax(): void
    {
        // Over the `track_count` column (ids: 1→3, 2→2, 3→5, 4→0, 5→4, 6→1): the full
        // inclusive min / max / max-only matrix. The null-column arm of the same contract
        // is refereed by orderedComparisonAndRangeOverANullableColumnExcludeNullsIdentically.
        self::assertSame(['1', '3', '5'], $this->sortedIds('/api/artists?filter[trackRange][min]=3', 'artists'));
        self::assertSame(['1', '2', '5'], $this->sortedIds('/api/artists?filter[trackRange][min]=2&filter[trackRange][max]=4', 'artists'));
        self::assertSame(['4', '6'], $this->sortedIds('/api/artists?filter[trackRange][max]=1', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterWithAnOpenBlankBoundIsNotA400(): void
    {
        // A blank bound is open-ended (treated as absent) — never a 400, identical on both.
        self::assertSame(['3', '5'], $this->sortedIds('/api/artists?filter[trackRange][min]=4&filter[trackRange][max]=', 'artists'));
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->sortedIds('/api/artists?filter[trackRange][min]=&filter[trackRange][max]=', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeFilterComparesTemporally(): void
    {
        self::assertSame(['3', '6', '7'], $this->sortedIds('/api/albums?filter[releasedRange][min]=2000-01-01', 'albums'));
        self::assertSame(['1', '3', '4'], $this->sortedIds('/api/albums?filter[releasedRange][min]=1995-01-01&filter[releasedRange][max]=2000-12-31', 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBooleanFilterCoercesAndSelectsIdentically(): void
    {
        self::assertSame(['3', '4', '7'], $this->sortedIds('/api/albums?filter[explicit]=1', 'albums'));
        self::assertSame(['1', '2', '5', '6'], $this->sortedIds('/api/albums?filter[explicit]=0', 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filtersCombineConjunctively(): void
    {
        // hasWebsite (1,3,5) AND track_count>=4 (3,5) → 3,5.
        self::assertSame(['3', '5'], $this->sortedIds('/api/artists?filter[hasWebsite]=1&filter[minTracks]=4', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyRendersA400ErrorDocument(): void
    {
        $response = $this->request('/api/albums?filter[nope]=x');

        $response->assertStatus(400);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTERING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[nope]'], $error['source'] ?? null);
    }

    // --- singular filters ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterCollapsesTheCollectionToASingleResource(): void
    {
        $document = $this->fetch('/api/artists?filter[slug]=radiohead');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // Zero-to-one carries no pagination meta even though the collection paginates.
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);
        self::assertArrayNotHasKey('page', $meta);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterWithNoMatchRendersDataNull(): void
    {
        // Zero matches is `data: null` with a 200 (the collection exists; the singular
        // result is simply empty) — not a 404.
        $document = $this->fetch('/api/artists?filter[slug]=no-such-slug');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function withoutTheSingularFilterTheSameEndpointStaysACollection(): void
    {
        $document = $this->fetch('/api/artists');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertArrayHasKey(0, $data);
    }

    // --- sorting -------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function sortingAscendingOrdersTheCollectionByByteOrder(): void
    {
        // Case-sensitive byte order (BINARY on SQLite, <=> in memory): the leading
        // uppercase "ARCA" sorts before the lowercase "aphex twin".
        self::assertSame(['6', '5', '3', '2', '1', '4'], $this->ids($this->fetch('/api/artists?sort=name'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aMinusPrefixSortsDescending(): void
    {
        self::assertSame(['4', '1', '2', '3', '5', '6'], $this->ids($this->fetch('/api/artists?sort=-name'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function multiFieldSortUsesTheFirstFieldAsPrimaryAndTheSecondAsTiebreak(): void
    {
        // status carries ties (released ×4, archived ×2, draft ×1), broken by the
        // unique title. A wrong composition (title primary) would reorder visibly.
        // archived: Blue Lines(5), Mezzanine(4); draft: amnesiac(7);
        // released: Dummy(2), Kid A(3), OK Computer(1), in rainbows(6).
        self::assertSame(['5', '4', '7', '2', '3', '1', '6'], $this->ids($this->fetch('/api/albums?sort=status,title'), 'albums'));

        // The secondary direction flips within each status group only.
        self::assertSame(['4', '5', '7', '6', '1', '3', '2'], $this->ids($this->fetch('/api/albums?sort=status,-title'), 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function anUnknownSortFieldRendersA400ErrorDocument(): void
    {
        $response = $this->request('/api/albums?sort=nope');

        $response->assertStatus(400);
        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('SORTING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'sort'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aDeclaredButUnsortableFieldRendersA400ErrorDocument(): void
    {
        // `explicit` is a declared attribute never opted into sorting.
        $response = $this->request('/api/albums?sort=explicit');

        $response->assertStatus(400);
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function sortingAgainstAnEmptyVocabularyRendersA400ErrorDocument(): void
    {
        // `genres` declares no sortable fields, so any ?sort is unsupported.
        $response = $this->request('/api/genres?sort=name');

        $response->assertStatus(400);
        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('SORTING_UNSUPPORTED', $error['code'] ?? null);
    }

    // --- pagination: the counted arm (albums) --------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountedPageWindowsTheSortedCollection(): void
    {
        // sort=title asc over all 7: [5,2,3,4,1,7,6]; page 2 of size 2 → [3,4].
        $document = $this->fetch('/api/albums?sort=title&page[number]=2&page[size]=2');

        self::assertSame(['3', '4'], $this->ids($document, 'albums'));

        // The single total fans to BOTH the top-level meta.total AND meta.page.total.
        self::assertSame(7, $this->topLevelMeta($document)['total'] ?? null);

        $meta = $this->pageMeta($document);
        self::assertSame(2, $meta['currentPage'] ?? null);
        self::assertSame(2, $meta['perPage'] ?? null);
        self::assertSame(7, $meta['total'] ?? null);
        self::assertSame(4, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function countedPaginationLinksNavigateAndPreserveTheQuery(): void
    {
        $document = $this->fetch('/api/albums?sort=title&page[number]=2&page[size]=2');
        $links = $this->links($document);

        foreach (['self', 'first', 'prev', 'next', 'last'] as $rel) {
            self::assertArrayHasKey($rel, $links, $rel);
        }

        self::assertSame(1, $this->pageNumberOf($links['first']));
        self::assertSame(1, $this->pageNumberOf($links['prev']));
        self::assertSame(3, $this->pageNumberOf($links['next']));
        self::assertSame(4, $this->pageNumberOf($links['last']));

        // Unrelated query params survive page navigation.
        self::assertStringContainsString('sort=title', $this->href($links['next']));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theLastCountedPageIsPartialAndHasNoNextLink(): void
    {
        // page 4 of size 2 over 7 rows holds the single trailing row.
        $document = $this->fetch('/api/albums?sort=title&page[number]=4&page[size]=2');

        self::assertSame(['6'], $this->ids($document, 'albums'));

        $links = $this->links($document);
        self::assertArrayNotHasKey('next', $links);
        self::assertArrayHasKey('prev', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function countedPaginationDefaultsApplyWithoutPageParameters(): void
    {
        // Default per-page is 15, so all 7 albums are page 1 of one page.
        $document = $this->fetch('/api/albums');

        self::assertCount(7, $this->ids($document, 'albums'));
        self::assertSame(7, $this->topLevelMeta($document)['total'] ?? null);

        $meta = $this->pageMeta($document);
        self::assertSame(1, $meta['currentPage'] ?? null);
        self::assertSame(7, $meta['total'] ?? null);
        self::assertSame(1, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anOutOfRangePageNumberIsServedAsTheFirstPageConsistently(): void
    {
        // page[number]=0 must serve AND describe page 1 — data, meta and links agree.
        $document = $this->fetch('/api/albums?sort=title&page[number]=0&page[size]=2');

        self::assertSame(['5', '2'], $this->ids($document, 'albums'));
        self::assertSame(1, $this->pageMeta($document)['currentPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aZeroPageSizeRendersADegenerateEmptyPageNotAnError(): void
    {
        // page[size]=0 is client-controlled: it must not 500 (division by zero in the
        // last-page math) — it renders an empty page with the total.
        $document = $this->fetch('/api/albums?page[size]=0');

        self::assertSame([], $this->ids($document, 'albums'));

        $meta = $this->pageMeta($document);
        self::assertSame(7, $meta['total'] ?? null);
        self::assertSame(0, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anOverLargePageSizeIsClampedToTheCap(): void
    {
        // page[size] is capped (max_per_page = 100): an over-large request clamps to
        // the cap rather than 400 — perPage reports the clamped size, all 7 fit.
        $document = $this->fetch('/api/albums?page[size]=1000');

        self::assertCount(7, $this->ids($document, 'albums'));

        $meta = $this->pageMeta($document);
        self::assertSame(100, $meta['perPage'] ?? null);
        self::assertSame(1, $meta['lastPage'] ?? null);
        self::assertSame(7, $meta['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function countedPaginationComposesWithFiltering(): void
    {
        // status=released keeps {1,2,3,6}; sort=title → [2,3,1,6]; page 2 of size 2 →
        // [1,6]; and the total reflects the FILTERED count.
        $document = $this->fetch('/api/albums?filter[status]=released&sort=title&page[number]=2&page[size]=2');

        self::assertSame(['1', '6'], $this->ids($document, 'albums'));
        self::assertSame(4, $this->topLevelMeta($document)['total'] ?? null);
        self::assertSame(4, $this->pageMeta($document)['total'] ?? null);
    }

    // --- pagination: the count-free arm (artists) ----------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountFreePageWindowsWithoutATotalOrLastLink(): void
    {
        // The default (count-free) arm: a bare page windows without a COUNT — no
        // top-level meta.total, no meta.page.total/lastPage, no `last` link; `next`
        // comes from the over-fetch hasMore. Default sort created_at asc → [1,4,3,2,5,6];
        // page 2 of size 2 → [3,2].
        $document = $this->fetch('/api/artists?page[number]=2&page[size]=2');

        self::assertSame(['3', '2'], $this->ids($document, 'artists'));
        self::assertArrayNotHasKey('total', $this->topLevelMeta($document));

        $meta = $this->pageMeta($document);
        self::assertSame(2, $meta['currentPage'] ?? null);
        self::assertSame(2, $meta['perPage'] ?? null);
        self::assertArrayNotHasKey('total', $meta);
        self::assertArrayNotHasKey('lastPage', $meta);

        $links = $this->links($document);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountFreePartialLastPageHasNoNextLink(): void
    {
        // 6 artists, size 4: page 2 holds the trailing 2 (5,6) with no further page.
        $document = $this->fetch('/api/artists?page[number]=2&page[size]=4');

        self::assertSame(['5', '6'], $this->ids($document, 'artists'));

        $links = $this->links($document);
        self::assertArrayNotHasKey('next', $links);
        self::assertArrayHasKey('prev', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountFreePageAlsoClampsAnOverLargeSize(): void
    {
        $document = $this->fetch('/api/artists?page[size]=1000');

        self::assertCount(6, $this->ids($document, 'artists'));
        self::assertSame(100, $this->pageMeta($document)['perPage'] ?? null);

        $links = $this->links($document);
        self::assertArrayNotHasKey('next', $links);
    }

    // --- sparse fieldsets ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function sparseFieldsetsNarrowASingleResourcesAttributes(): void
    {
        $document = $this->fetch('/api/albums/1?fields[albums]=title');

        $attributes = $this->attributesOf($document);
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayNotHasKey('averageRating', $attributes);
        self::assertArrayNotHasKey('status', $attributes);
        self::assertArrayNotHasKey('explicit', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function sparseFieldsetsNarrowACollectionsAttributes(): void
    {
        $document = $this->fetch('/api/albums?fields[albums]=title,status');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertNotEmpty($data);

        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            self::assertArrayHasKey('title', $attributes);
            self::assertArrayHasKey('status', $attributes);
            self::assertArrayNotHasKey('averageRating', $attributes);
            self::assertArrayNotHasKey('releasedAt', $attributes);
        }
    }

    // --- single-resource reads -----------------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function aSingleResourceRendersItsTypedAttributes(): void
    {
        $document = $this->fetch('/api/albums/1');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $this->attributesOf($document);
        self::assertSame('OK Computer', $attributes['title'] ?? null);
        self::assertSame('released', $attributes['status'] ?? null);
        self::assertSame(9.8, $attributes['averageRating'] ?? null);
        self::assertFalse($attributes['explicit'] ?? null);

        $releasedAt = $attributes['releasedAt'] ?? null;
        self::assertIsString($releasedAt);
        self::assertStringStartsWith('1997-05-21', $releasedAt);

        $availableFrom = $attributes['availableFrom'] ?? null;
        self::assertIsString($availableFrom);
        self::assertStringStartsWith('1997-05-21', $availableFrom);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function attributeTypesRenderWithTheirDeclaredJsonType(): void
    {
        // A boolean renders as a JSON bool, a decimal as a JSON number — identically
        // off the in-memory POPO and the Eloquent cast attribute.
        $attributes = $this->attributesOf($this->fetch('/api/albums/3'));

        self::assertIsBool($attributes['explicit'] ?? null);
        self::assertTrue($attributes['explicit'] ?? null);
        self::assertIsFloat($attributes['averageRating'] ?? null);
        self::assertIsInt($this->attributesOf($this->fetch('/api/artists/1'))['trackCount'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function nullAttributesRenderAsNull(): void
    {
        // Album 4 has a null average_rating; artist 2 a null website + bio. A nullable
        // attribute is rendered as an explicit `null` member (present, not omitted).
        $album = $this->attributesOf($this->fetch('/api/albums/4'));
        self::assertArrayHasKey('averageRating', $album);
        self::assertNull($album['averageRating']);
        self::assertTrue($album['explicit'] ?? null);

        $artist = $this->attributesOf($this->fetch('/api/artists/2'));
        self::assertArrayHasKey('website', $artist);
        self::assertNull($artist['website']);
        self::assertArrayHasKey('bio', $artist);
        self::assertNull($artist['bio']);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aResourceWithANaturalStringKeyIsFetchable(): void
    {
        $document = $this->fetch('/api/genres/trip-hop');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('genres', $data['type'] ?? null);
        self::assertSame('trip-hop', $data['id'] ?? null);
        self::assertSame('Trip Hop', $this->attributesOf($document)['name'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anUnknownIdRendersA404ErrorDocument(): void
    {
        $numeric = $this->request('/api/artists/9999');
        $numeric->assertStatus(404);
        $numeric->assertHeader('Content-Type', self::MEDIA_TYPE);
        self::assertSame('404', $this->firstError($numeric)['status'] ?? null);

        $this->request('/api/genres/no-such-genre')->assertStatus(404);
    }

    // --- the top-level query-param 400 ---------------------------------------

    #[Test]
    #[Group('spec:query-parameters')]
    #[Group('spec:errors')]
    public function anUnrecognizedTopLevelQueryParameterRendersA400ErrorDocument(): void
    {
        $response = $this->request('/api/albums?bogus=1');

        $response->assertStatus(400);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
    }

    // --- explicitly deferred (kept visible, not deleted) ---------------------

    // --- Phase 3a: relationship reads land (formerly deferred, now asserted) -------
    //
    // These four blocks were the Phase-1/2 placeholders for the relationship-read
    // surface. Phase 3a wires the `albums`/`artist` relations on BOTH providers over the
    // SAME object graph (the FK the Eloquent hasMany/belongsTo keys on; the wired POPO
    // properties the witness reads), so the SAME assertion runs against SQL and the
    // witness — a divergence localizes to one provider (the referee premise). The fuller
    // relationship-read matrix (batch-window edges, load-state, withCount, linkage
    // shapes) lives in the dedicated {@see RelationshipReadConformanceTestCase}.

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function relationshipExistenceFiltersMatchIdenticallyOnBothProviders(): void
    {
        // `filter[withAlbums]`/`filter[withoutAlbums]` are the WhereHas / WhereDoesntHave
        // semi-join: Radiohead (1), Portishead (2) and Massive Attack (3) own albums;
        // aphex twin (4), Boards of Canada (5) and ARCA (6) own none. The Eloquent
        // provider compiles an EXISTS / NOT EXISTS subquery; the witness runs the
        // reference predicate over the object graph — identical sets on both.
        self::assertSame(['1', '2', '3'], $this->sortedIds('/api/artists?filter[withAlbums]', 'artists'));
        self::assertSame(['4', '5', '6'], $this->sortedIds('/api/artists?filter[withoutAlbums]', 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aWhereThroughDottedPathFilterIsAnExistsAnySemiJoin(): void
    {
        // `filter[albumTitled]` traverses `albums.title` and keeps an artist who has SOME
        // album with that title. Radiohead owns four albums including `amnesiac`, so the
        // semi-join returns it ONCE (never row-multiplied by the four-album fan-out) —
        // the same result whether Eloquent nests the leaf predicate in the EXISTS or the
        // witness walks the object graph.
        self::assertSame(['1'], $this->ids($this->fetch('/api/artists?filter[albumTitled]=amnesiac'), 'artists'));
        // A title owned by a single-album artist.
        self::assertSame(['2'], $this->ids($this->fetch('/api/artists?filter[albumTitled]=Dummy'), 'artists'));
        // A title nobody owns matches nothing.
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[albumTitled]=Nonexistent'), 'artists'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function compoundDocumentsViaIncludeExpandTheRelatedResources(): void
    {
        // to-one: ?include=artist carries the owner's linkage data AND expands it into the
        // compound `included` array.
        $toOne = $this->fetch('/api/albums/1?include=artist');
        self::assertSame(['type' => 'artists', 'id' => '1'], $this->relationshipData($toOne, 'artist'));
        self::assertSame(['1'], $this->includedIds($toOne, 'artists'));

        // to-many: ?include=albums expands all four of Radiohead's albums.
        $toMany = $this->fetch('/api/artists/1?include=albums');
        $linkage = $this->relationshipData($toMany, 'albums');
        self::assertIsArray($linkage);
        self::assertCount(4, $linkage);
        self::assertSame(['1', '3', '6', '7'], $this->includedIds($toMany, 'albums'));

        // deep (depth 2, within the max_include_depth of 3): ?include=artist.albums walks
        // album 1 → its artist → that artist's albums. The primary album 1 is NOT repeated
        // in `included` (compound-document dedup), so it is artist 1 + albums 3/6/7.
        $deep = $this->fetch('/api/albums/1?include=artist.albums');
        self::assertSame(['1'], $this->includedIds($deep, 'artists'));
        self::assertSame(['3', '6', '7'], $this->includedIds($deep, 'albums'));

        // sparse fieldset on an included type: only the requested attribute renders.
        $sparse = $this->fetch('/api/artists/1?include=albums&fields[albums]=title');
        $album = $this->firstIncluded($sparse, 'albums');
        $attributes = $album['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame(['title'], \array_keys($attributes));

        // a path deeper than the max include depth (3) is a 400 before any fetch.
        $tooDeep = $this->request('/api/albums/1?include=artist.albums.artist.albums');
        $tooDeep->assertStatus(400);
        self::assertSame('INCLUSION_DEPTH_EXCEEDED', $this->firstError($tooDeep)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedAndRelationshipEndpointsRenderTheRelation(): void
    {
        // related to-many: GET /artists/1/albums renders the albums collection under the
        // albums resource's own default sort (released_at DESC) + counting paginator.
        $related = $this->fetch('/api/artists/1/albums');
        self::assertSame(['6', '7', '3', '1'], $this->ids($related, 'albums'));
        self::assertSame(4, $this->topLevelMeta($related)['total'] ?? null);

        // related to-many, empty: artist 4 owns none.
        $empty = $this->fetch('/api/artists/4/albums');
        self::assertSame([], $this->ids($empty, 'albums'));
        self::assertSame(0, $this->topLevelMeta($empty)['total'] ?? null);

        // related to-one: GET /albums/1/artist renders the single owner resource.
        $toOne = $this->fetch('/api/albums/1/artist');
        $data = $toOne['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // relationship to-many: GET /artists/1/relationships/albums renders linkage only.
        $linkage = $this->fetch('/api/artists/1/relationships/albums');
        $identifiers = $linkage['data'] ?? null;
        self::assertIsArray($identifiers);
        self::assertCount(4, $identifiers);
        foreach ($identifiers as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame(['type', 'id'], \array_keys($identifier));
            self::assertSame('albums', $identifier['type']);
        }

        // relationship to-one linkage.
        $toOneLinkage = $this->fetch('/api/albums/1/relationships/artist');
        self::assertSame(['type' => 'artists', 'id' => '1'], $toOneLinkage['data'] ?? null);

        // 404 arms: missing parent, unknown related relation, unknown relationship.
        $this->request('/api/artists/9999/albums')->assertStatus(404);
        $this->request('/api/artists/1/nonsense')->assertStatus(404);
        $this->request('/api/artists/1/relationships/nonsense')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMalformedFilterValueRendersA400(): void
    {
        // Phase 2 (always-on validator bridge): a malformed filter value is validated
        // against the filter's declared value constraints BEFORE the provider runs, so it
        // is a clean `400` FILTER_VALUE_INVALID (located by source.parameter) — identical
        // on BOTH providers, never a silent non-match in memory or a driver error on SQL.

        // A non-numeric bound of the numeric `trackRange` Range fails its numeric shape.
        $range = $this->request('/api/artists?filter[trackRange][min]=banana');
        $range->assertStatus(400);
        $range->assertHeader('Content-Type', self::MEDIA_TYPE);
        self::assertSame('400', $range->json('errors.0.status'));
        self::assertSame('FILTER_VALUE_INVALID', $range->json('errors.0.code'));
        self::assertSame('filter[trackRange]', $range->json('errors.0.source.parameter'));

        // A calendar-invalid bound of the `releasedRange` DateRange (the shape Pattern is
        // lenient on the calendar) is caught by the temporal-validity check.
        $date = $this->request('/api/albums?filter[releasedRange][min]=1997-13-99T00:00:00Z');
        $date->assertStatus(400);
        self::assertSame('400', $date->json('errors.0.status'));
        self::assertSame('FILTER_VALUE_INVALID', $date->json('errors.0.code'));
        self::assertSame('filter[releasedRange]', $date->json('errors.0.source.parameter'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function clientDrivenWithCountSelfIsRejectedUntilTheResourceOptsIn(): void
    {
        // Phase 3a registers core's Countable profile on every Server (ServerFactory), so the
        // CLIENT-driven `?withCount=_self_` opt-in IS now recognized once the client
        // negotiates the profile — the capability the old Phase-1 marker said was unwired is
        // live (this un-skips it). But `_self_` counts the primary collection ITSELF, which
        // core gates on the resource opting in via CountableSelfInterface; the workbench
        // `artists` resource does not, so core's safe default rejects it with a `400`
        // (RELATIONSHIP_COUNT_NOT_ALLOWED) BEFORE the provider runs — identical on both
        // providers. The AUTHOR-side counted total (PagePaginator::withCount()) is fully
        // refereed by the counted `albums` arm; a resource-level `_self_` opt-in is a
        // Phase-4 surface concern.
        $response = $this->get('/api/artists?withCount=_self_', [
            'Accept' => self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"',
        ]);

        $response->assertStatus(400);
        self::assertSame('400', $response->json('errors.0.status'));
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $response->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function orderedComparisonAndRangeOverANullableColumnExcludeNullsIdentically(): void
    {
        // Core ADR 0116 (an ordered comparison against `null` never matches) resolved the
        // divergence docs/adr/0003 deferred to core: the in-memory witness now excludes
        // null rows from an ordered `<`/`<=`/`>`/`>=` — and therefore a Range bound —
        // exactly as Eloquent/SQL three-valued logic already did, instead of coercing
        // `null` toward 0. So the `rating` Range over the NULLABLE `average_rating` column
        // (albums 4 and 7 are unrated — see nullPresenceFiltersSelectIdentically) now
        // returns the SAME set on BOTH providers, the null rows never coerced in.

        // A min-bound (a `>=` arm): ratings >= 9.0 → 1 (9.8), 2 (9.1), 3 (9.5), 6 (9.0).
        self::assertSame(['1', '2', '3', '6'], $this->sortedIds('/api/albums?filter[rating][min]=9.0', 'albums'));
        // A bounded range excludes 1 (9.8): 9.0–9.5 → 2 (9.1), 3 (9.5), 6 (9.0).
        self::assertSame(['2', '3', '6'], $this->sortedIds('/api/albums?filter[rating][min]=9.0&filter[rating][max]=9.5', 'albums'));
        // The crucial null case — a MAX-only bound (a `<=` arm): `null <= 9.0` was the
        // coercion the OLD in-memory witness matched (the ADR 0003 divergence); under core
        // ADR 0116 both providers now EXCLUDE the unrated rows (4, 7), keeping only 5 (8.7)
        // and 6 (9.0) — the null-comparison parity this suite could not prove before.
        self::assertSame(['5', '6'], $this->sortedIds('/api/albums?filter[rating][max]=9.0', 'albums'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function likeWildcardCharactersInTheSearchValueMatchLiterally(): void
    {
        // The in-memory witness treats a `like`/`starts`/`ends` value literally
        // (stripos), and the Eloquent handler escapes the LIKE wildcards under an
        // explicit ESCAPE '!' (blueprint R1), so a `%` or `_` in the search term
        // matches a LITERAL `%`/`_` on BOTH providers — never every row. No fixture
        // name/title carries one, so both return the empty set; an UNESCAPED LIKE would
        // match every row on Eloquent (a maximal divergence the witness would catch).
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[nameContains]=%25'), 'artists'));
        self::assertSame([], $this->ids($this->fetch('/api/artists?filter[nameContains]=_'), 'artists'));
        self::assertSame([], $this->ids($this->fetch('/api/albums?filter[title]=%25'), 'albums'));
        self::assertSame([], $this->ids($this->fetch('/api/albums?filter[titleStarts]=_'), 'albums'));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Issues a GET carrying the JSON:API `Accept` header and returns the raw response
     * (for status/error assertions).
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response with the right media type.
     *
     * @return array<string, mixed>
     */
    protected function fetch(string $path): array
    {
        $response = $this->request($path);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $document = $response->json();
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * The ids of the document's primary data, in document order, asserting each
     * member carries `$type`.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function ids(array $document, string $type): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame($type, $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The numerically-sorted ids of `$path` — a stable order for set-membership
     * assertions that does not depend on any declared default sort.
     *
     * @return list<string>
     */
    protected function sortedIds(string $path, string $type): array
    {
        $ids = $this->ids($this->fetch($path), $type);
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    /**
     * The attributes of the document's primary (single) resource.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function attributesOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * The document's top-level `meta` (asserted to be an array).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function topLevelMeta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * The document's `meta.page` (asserted present).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * The document's `links`, with null links dropped so `array_key_exists` reflects
     * genuine presence (self/first always; prev/next/last conditionally).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function links(array $document): array
    {
        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        /** @var array<string, mixed> $filtered */
        $filtered = \array_filter($links, static fn(mixed $link): bool => $link !== null);

        return $filtered;
    }

    /**
     * The document's first error object.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    protected function firstError(TestResponse $response): array
    {
        $document = $response->json();
        self::assertIsArray($document);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * The `page[number]` a pagination link points at.
     */
    protected function pageNumberOf(mixed $link): int
    {
        \parse_str((string) \parse_url($this->href($link), \PHP_URL_QUERY), $query);

        $page = $query['page'] ?? null;
        self::assertIsArray($page);

        $number = $page['number'] ?? null;

        return \is_scalar($number) ? (int) $number : -1;
    }

    /**
     * A document link's href, whether it rendered as a string or a link object.
     */
    protected function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }

    /**
     * The `data` of the primary single resource's `$name` relationship object (a to-one
     * identifier or a list of to-many identifiers), asserted present.
     *
     * @param array<string, mixed> $document
     */
    protected function relationshipData(array $document, string $name): mixed
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);
        self::assertArrayHasKey('data', $relationship);

        return $relationship['data'];
    }

    /**
     * The numerically-sorted ids of the compound document's `included` resources of
     * `$type` (empty when none, so a to-one/to-many expansion asserts a stable set).
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function includedIds(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $ids = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === $type) {
                $id = $resource['id'] ?? null;
                self::assertIsString($id);
                $ids[] = $id;
            }
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    /**
     * The first `included` resource of `$type`, asserted present.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function firstIncluded(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === $type) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        self::fail("No included resource of type '{$type}'.");
    }
}
