<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The PIVOT-bearing related-collection cursor (keyset) pagination acceptance suite —
 * the `belongsToMany` twin of {@see RelatedCursorConformanceTestCase} — asserted
 * byte-identical over HTTP on the in-memory witness
 * ({@see InMemoryPivotCursorConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentPivotCursorConformanceTest}) over the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::cursorBoards()} partition of the
 * shared cursor-widget rows (docs/adr/0017).
 *
 * The `cursorBoards.widgets` relation is a pivot-carrying `belongsToMany` (a declared
 * read-only `position` pivot field) declaring its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator} (default size 2), so the walk
 * proves the keyset composes on top of the pivot INNER JOIN: forward/next
 * (`page[after]`) and backward/prev (`page[before]`) token round-trips over the
 * parent-scoped membership, boundary correctness at every page edge, each member's
 * stored pivot rendered as `meta.pivot` on the SAME cursor page, links scoped to the
 * RELATED URL with never a `last`, and the published cursor-pagination profile
 * advertised (the suite registers it via `jsonapi.profiles`) in `jsonapi.profile` +
 * the `Content-Type` `profile` parameter.
 */
abstract class PivotCursorConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The Content-Type of a cursor page: the suite registers the cursor-pagination
     * profile, so every keyset page advertises it as a media-type parameter.
     */
    public const string CURSOR_CONTENT_TYPE = self::MEDIA_TYPE . '; profile="' . CursorPaginationProfile::URI . '"';

    /**
     * The workbench service provider that wires exactly ONE provider pair (in-memory
     * or Eloquent) over the isolated cursorBoards → cursorWidgets resources.
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
     * Registers the published cursor-pagination profile (`jsonapi.profiles`) so the
     * cursor pages advertise it — the profile-advertising half of the contract.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.profiles', [CursorPaginationProfile::class]);
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

    // --- the relation-declared paginator resolves over the pivot join ----------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aBareRelatedRequestPagesAtTheRelationDeclaredDefaultSize(): void
    {
        // No ?page at all: the relation's own CursorPaginator (default size 2) must
        // resolve over the pivot-joined membership — a keyset page of two, with a
        // `next` token minted for the third row.
        [$ids, $links] = $this->page('/api/cursorBoards/1/widgets');
        self::assertSame(['1', '2'], $ids);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
    }

    // --- forward / backward round-trips scoped to the pivot membership ---------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksThePivotScopedCollectionInFixedPages(): void
    {
        // Board 1's pivot owns widgets 1,2,3,4,5,7. sort=priority,id asc (nulls last):
        //   10:(2,7) 20:(5) 30:(1,4) null:(3)
        self::assertSame(
            ['2', '7', '5', '1', '4', '3'],
            $this->walkForward('/api/cursorBoards/1/widgets?sort=priority,id', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function pkOnlyPagingWithNoSortWalksIdOrderScopedToThePivot(): void
    {
        // No ?sort → keyset is PK-only (id asc), restricted to board 1's pivot rows —
        // and the widget's OWN id stays on the wire (the pivot table's colliding `id`
        // never leaks through the join).
        self::assertSame(
            ['1', '2', '3', '4', '5', '7'],
            $this->walkForward('/api/cursorBoards/1/widgets', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anotherParentsMembersNeverLeakIntoThePage(): void
    {
        // Board 2's pivot owns ONLY widgets 6 and 8. sort=priority,id: 20:(8) null:(6).
        self::assertSame(
            ['8', '6'],
            $this->walkForward('/api/cursorBoards/2/widgets?sort=priority,id', 1),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingFromADeepPageEqualsTheForwardPages(): void
    {
        $forwardPages = $this->forwardPages('/api/cursorBoards/1/widgets?sort=priority,id', 2);
        self::assertGreaterThanOrEqual(3, \count($forwardPages));

        self::assertSame(
            \array_map(static fn(array $page): array => $page['ids'], $forwardPages),
            $this->backwardPagesFrom($forwardPages[\count($forwardPages) - 1]['path']),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNullableColumnDescPutsNullsFirstWithinThePivotScope(): void
    {
        // sort=-priority,-id over board 1: the NULL row (3) first (NULL=largest), then
        // 30:(4,1) 20:(5) 10:(7,2) with the appended PK following -priority (desc).
        self::assertSame(
            ['3', '4', '1', '5', '7', '2'],
            $this->walkForward('/api/cursorBoards/1/widgets?sort=-priority,-id', 2),
        );
    }

    // --- pivot meta on every member of every cursor page -----------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function everyMemberOfEveryCursorPageCarriesItsStoredPivotMeta(): void
    {
        // The pivot map (position = widget id × 10) must render as `meta.pivot` on
        // each member of each keyset page — the page() helper asserts it per member,
        // so a full walk proves the wrap survives every page boundary.
        self::assertSame(
            ['2', '7', '5', '1', '4', '3'],
            $this->walkForward('/api/cursorBoards/1/widgets?sort=priority,id', 2),
        );
    }

    // --- links + profile shape --------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorLinksAreScopedToTheRelatedUrlAndNeverEmitALast(): void
    {
        [, $links] = $this->page('/api/cursorBoards/1/widgets?sort=priority,id&page[size]=2');

        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links);
        foreach (['self', 'first', 'next'] as $rel) {
            if (isset($links[$rel])) {
                self::assertStringContainsString('/api/cursorBoards/1/widgets', $this->href($links[$rel]));
            }
        }

        // The exhausting page: next carries the tail, then no further next and never a last.
        [$ids, $links] = $this->page('/api/cursorBoards/1/widgets?sort=priority,id&page[size]=4');
        self::assertSame(['2', '7', '5', '1'], $ids);
        [$ids, $links] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertSame(['4', '3'], $ids);
        self::assertArrayNotHasKey('next', $links);
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theCursorPageAdvertisesThePublishedCursorPaginationProfile(): void
    {
        $response = $this->request('/api/cursorBoards/1/widgets?sort=priority,id&page[size]=2');
        $response->assertOk();

        // The registered profile rides the Content-Type `profile` media-type parameter…
        $response->assertHeader('Content-Type', self::CURSOR_CONTENT_TYPE);

        // …and the top-level `jsonapi.profile` member (the 1.1 advertising location).
        $document = $response->json();
        self::assertIsArray($document);
        $jsonapi = $document['jsonapi'] ?? null;
        self::assertIsArray($jsonapi);
        $profiles = $jsonapi['profile'] ?? null;
        self::assertIsArray($profiles);
        self::assertContains(CursorPaginationProfile::URI, $profiles);
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
     * Fetches a cursor page and returns `[ids, links]` (null links dropped). Every
     * member must be a `cursorWidgets` resource carrying its stored pivot as
     * `meta.pivot.position` (= widget id × 10) — asserted on EVERY page so the pivot
     * wrap provably composes with the cursor branch at each boundary.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function page(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();
        $response->assertHeader('Content-Type', self::CURSOR_CONTENT_TYPE);

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

            $meta = $resource['meta'] ?? null;
            self::assertIsArray($meta, \sprintf('member %s must carry meta', $id));
            $pivot = $meta['pivot'] ?? null;
            self::assertIsArray($pivot, \sprintf('member %s must carry meta.pivot', $id));
            self::assertSame(((int) $id) * 10, $pivot['position'] ?? null);
        }

        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        $links = \array_filter($links, static fn(mixed $link): bool => $link !== null);

        return [$ids, $links];
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
}
