<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The RELATED-collection cursor (keyset) pagination acceptance suite — the
 * `GET /{type}/{id}/{rel}` twin of {@see CursorConformanceTestCase} — asserted
 * byte-identical over HTTP on the in-memory witness
 * ({@see InMemoryRelatedCursorConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentRelatedCursorConformanceTest}) over the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::cursorGroups()} partition of the
 * shared cursor-widget rows (docs/adr/0016, bundle ADR 0063).
 *
 * The `cursorGroups.widgets` relation declares its OWN {@see \haddowg\JsonApi\Pagination\CursorPaginator}
 * (default size 2), so the walk proves the relation-declared paginator resolves end to
 * end: the parent-scoped keyset (group 1's six members; group 2's rows never leak),
 * forward/next and backward/prev token round-trips, the null buckets and PK tiebreak
 * inside the parent scope, links scoped to the RELATED URL (never the primary
 * collection's), no `last` link, `meta.page{perPage,from,to,hasMore}`, and the
 * stale/malformed cursor 400s.
 */
abstract class RelatedCursorConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The workbench service provider that wires exactly ONE provider pair (in-memory
     * or Eloquent) over the isolated cursorGroups → cursorWidgets resources.
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

    // --- the relation-declared paginator resolves -----------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aBareRelatedRequestPagesAtTheRelationDeclaredDefaultSize(): void
    {
        // No ?page at all: the relation's own CursorPaginator (default size 2) must
        // resolve — a keyset page of two, with a `next` token minted for the third row.
        [$ids, $links] = $this->page('/api/cursorGroups/1/widgets');
        self::assertSame(['1', '2'], $ids);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
    }

    // --- forward / backward round-trips scoped to the parent -------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheParentScopedCollectionInFixedPages(): void
    {
        // Group 1 owns widgets 1,2,3,4,5,7. sort=priority,id asc (nulls last):
        //   10:(2,7) 20:(5) 30:(1,4) null:(3)
        self::assertSame(
            ['2', '7', '5', '1', '4', '3'],
            $this->walkForward('/api/cursorGroups/1/widgets?sort=priority,id', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function pkOnlyPagingWithNoSortWalksIdOrderScopedToTheParent(): void
    {
        // No ?sort → keyset is PK-only (id asc), restricted to group 1's members.
        self::assertSame(
            ['1', '2', '3', '4', '5', '7'],
            $this->walkForward('/api/cursorGroups/1/widgets', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anotherParentsMembersNeverLeakIntoThePage(): void
    {
        // Group 2 owns ONLY widgets 6 and 8. sort=priority,id: 20:(8) null:(6).
        self::assertSame(
            ['8', '6'],
            $this->walkForward('/api/cursorGroups/2/widgets?sort=priority,id', 1),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingFromADeepPageEqualsTheForwardPages(): void
    {
        $forwardPages = $this->forwardPages('/api/cursorGroups/1/widgets?sort=priority,id', 2);
        self::assertGreaterThanOrEqual(3, \count($forwardPages));

        self::assertSame(
            \array_map(static fn(array $page): array => $page['ids'], $forwardPages),
            $this->backwardPagesFrom($forwardPages[\count($forwardPages) - 1]['path']),
        );
    }

    // --- null buckets + tiebreak inside the parent scope ----------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNullableColumnDescPutsNullsFirstWithinTheParentScope(): void
    {
        // sort=-priority,-id over group 1: the NULL row (3) first (NULL=largest), then
        // 30:(4,1) 20:(5) 10:(7,2) with the appended PK following -priority (desc).
        self::assertSame(
            ['3', '4', '1', '5', '7', '2'],
            $this->walkForward('/api/cursorGroups/1/widgets?sort=-priority,-id', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function mixedAscDescMultiColumnPagesInTheForcedOrderWithinTheParentScope(): void
    {
        // sort=category,-priority over group 1: category asc, priority DESC (nulls
        // FIRST in desc), PK tiebreak follows -priority (desc).
        //   guide: 30:(4,1) 10:(7,2)   news: null:(3) 20:(5)
        self::assertSame(
            ['4', '1', '7', '2', '3', '5'],
            $this->walkForward('/api/cursorGroups/1/widgets?sort=category,-priority', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function dateKeyedSortPagesChronologicallyAcrossBoundaries(): void
    {
        // sort=releasedAt,id asc over group 1: non-null dates chronologically, then the
        // NULL row (4) last — the ISO-8601 mint → typed datetime bind round-trips at a
        // page boundary landing on a date row.
        //   2024-01-05:1, 2024-01-20:5, 2024-02-10:3, 2024-03-01:2, 2024-05-01:7, null:4
        self::assertSame(
            ['1', '5', '3', '2', '7', '4'],
            $this->walkForward('/api/cursorGroups/1/widgets?sort=releasedAt,id', 2),
        );
    }

    // --- links + meta shape ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorLinksAreScopedToTheRelatedUrl(): void
    {
        [, $links] = $this->page('/api/cursorGroups/1/widgets?sort=priority,id&page[size]=2');

        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links);
        foreach (['self', 'first', 'next'] as $rel) {
            if (isset($links[$rel])) {
                self::assertStringContainsString('/api/cursorGroups/1/widgets', $this->href($links[$rel]));
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theExhaustingPageEmitsNoNextAndNeverALastLink(): void
    {
        [$ids, $links] = $this->page('/api/cursorGroups/1/widgets?sort=priority,id&page[size]=4');
        self::assertSame(['2', '7', '5', '1'], $ids);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
        // first always, last never (cursor pages omit `last` by design).
        self::assertArrayHasKey('first', $links);
        self::assertArrayNotHasKey('last', $links);

        [$ids, $links] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertSame(['4', '3'], $ids);
        self::assertArrayNotHasKey('next', $links);
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theFirstPageCarriesCursorPageMeta(): void
    {
        $document = $this->fetch('/api/cursorGroups/1/widgets?sort=priority,id&page[size]=2');

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(2, $page['perPage'] ?? null);
        self::assertSame('2', $page['from'] ?? null);
        self::assertSame('7', $page['to'] ?? null);
        self::assertTrue($page['hasMore'] ?? null);
    }

    // --- before wins over after ----------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function beforeWinsOverAfterWhenBothAreSupplied(): void
    {
        [, $firstLinks] = $this->page('/api/cursorGroups/1/widgets?sort=priority,id&page[size]=2');
        [$secondIds, $secondLinks] = $this->page($this->relativePath($this->href($firstLinks['next'])));
        self::assertSame(['5', '1'], $secondIds);

        $afterToken = $this->cursorParam($this->href($secondLinks['next']), 'after');
        $beforeToken = $this->cursorParam($this->href($secondLinks['prev']), 'before');

        // Both supplied: before (page 1: 2,7) must win over after (page 3: 4,3).
        [$ids] = $this->page(\sprintf(
            '/api/cursorGroups/1/widgets?sort=priority,id&page[size]=2&page[after]=%s&page[before]=%s',
            \rawurlencode($afterToken),
            \rawurlencode($beforeToken),
        ));

        self::assertSame(['2', '7'], $ids);
    }

    // --- stale / malformed 400 -----------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aCursorReusedUnderADifferentSortColumnIsAStale400(): void
    {
        [, $links] = $this->page('/api/cursorGroups/1/widgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->request(\sprintf(
            '/api/cursorGroups/1/widgets?sort=category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        $response->assertStatus(400);
        $error = $this->firstError($response);
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aMalformedCursorIsA400(): void
    {
        $response = $this->request('/api/cursorGroups/1/widgets?sort=priority,id&page[after]=not-base64url!!');

        $response->assertStatus(400);
        $error = $this->firstError($response);
        self::assertSame('CURSOR_MALFORMED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
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
     * Walks forward from `$path` following `next` until exhausted, returning the
     * concatenated ids in document order.
     *
     * @return list<string>
     */
    private function walkForward(string $path, int $size): array
    {
        $path .= (\str_contains($path, '?') ? '&' : '?') . 'page[size]=' . $size;

        $ids = [];
        $guard = 0;
        while (true) {
            [$pageIds, $links] = $this->page($path);
            foreach ($pageIds as $id) {
                $ids[] = $id;
            }
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(20, ++$guard, 'forward paging must terminate');
        }

        return $ids;
    }

    /**
     * The forward pages from `$path` as `[{ids, path}, …]`, capturing each page's
     * request path so a backward walk can start from the deepest one.
     *
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $path, int $size): array
    {
        $path .= (\str_contains($path, '?') ? '&' : '?') . 'page[size]=' . $size;

        $pages = [];
        $guard = 0;
        while (true) {
            [$pageIds, $links] = $this->page($path);
            $pages[] = ['ids' => $pageIds, 'path' => $path];
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(20, ++$guard, 'forward paging must terminate');
        }

        return $pages;
    }

    /**
     * Walks backward from `$path` following `prev` to the head, returning the pages in
     * natural forward order (each page's ids stay in forward order).
     *
     * @return list<list<string>>
     */
    private function backwardPagesFrom(string $path): array
    {
        $pages = [];
        $guard = 0;
        while (true) {
            [$ids, $links] = $this->page($path);
            $pages[] = $ids;
            if (!isset($links['prev'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['prev']));
            self::assertLessThan(20, ++$guard, 'backward paging must terminate');
        }

        return \array_reverse($pages);
    }

    /**
     * Fetches a cursor page and returns `[ids, links]` (null links dropped).
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function page(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $document = $response->json();
        self::assertIsArray($document);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('cursorWidgets', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        $links = \array_filter($links, static fn(mixed $link): bool => $link !== null);

        return [$ids, $links];
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function firstError(TestResponse $response): array
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

    private function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }

    /**
     * The path + query of an absolute link, for re-issuing through the test app.
     */
    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * The `page[$key]` cursor token from an absolute link href.
     */
    private function cursorParam(string $url, string $key): string
    {
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;
        self::assertIsArray($page);
        $token = $page[$key] ?? null;
        self::assertIsString($token);

        return $token;
    }
}
