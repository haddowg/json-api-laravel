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
 * The relationship-LINKAGE cursor (keyset) pagination acceptance suite — the
 * `GET /{type}/{id}/relationships/{rel}` twin of {@see RelatedCursorConformanceTestCase}
 * — asserted byte-identical over HTTP on the in-memory witness
 * ({@see InMemoryLinkageCursorConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentLinkageCursorConformanceTest}) over the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::cursorGroups()} partition the
 * related-cursor suite walks (docs/adr/0017, core ADR 0124).
 *
 * The `cursorGroups.widgets` relation declares its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator}, so a QUERIED linkage read
 * (`?page[size]`/`page[after]`/`page[before]`/`?sort`) windows the identifier set to a
 * keyset page: members stay identifier-only (`type` + `id`, no attributes), the
 * document links carry the real `page[after]`/`page[before]` cursors (never a `last`),
 * the body stays links-only (no `meta.page` — core ADR 0124), and the published
 * cursor-pagination profile (registered via `jsonapi.profiles`) is advertised through
 * the NEW core `IdentifierResponse::withPage()` seam in `jsonapi.profile` + the
 * `Content-Type` `profile` parameter. A bare (unqueried) linkage GET still renders the
 * whole association (the preserved full-linkage contract).
 */
abstract class LinkageCursorConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The Content-Type of a cursor linkage page: the suite registers the
     * cursor-pagination profile, so every keyset page advertises it.
     */
    public const string CURSOR_CONTENT_TYPE = self::MEDIA_TYPE . '; profile="' . CursorPaginationProfile::URI . '"';

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
     * Registers the published cursor-pagination profile (`jsonapi.profiles`) so the
     * windowed linkage pages advertise it — the profile-advertising half of the
     * `IdentifierResponse::withPage()` contract (core ADR 0124) — and sets the
     * server `base_uri` to carry the `/api` route prefix: a linkage document's links
     * (self/related and the windowed pagination set) derive from the SERVER base URI
     * (not the request URI the related endpoint's page links ride), exactly as a
     * deployment behind a route prefix configures it.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.profiles', [CursorPaginationProfile::class]);
        $config->set('jsonapi.base_uri', 'http://localhost/api');
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

    // --- the queried linkage endpoint windows to a keyset page ------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aQueriedLinkageRequestWindowsTheIdentifiersToAKeysetPage(): void
    {
        // Group 1 owns widgets 1,2,3,4,5,7; PK-only keyset (no ?sort), page[size]=2.
        [$ids, $links] = $this->page('/api/cursorGroups/1/relationships/widgets?page[size]=2');
        self::assertSame(['1', '2'], $ids);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
        self::assertStringContainsString('page%5Bafter%5D=', $this->href($links['next']));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aBareLinkageRequestStillRendersTheFullAssociation(): void
    {
        // No query parameters → the whole association off the loaded parent (the
        // preserved full-linkage contract; cursor windowing is on-demand only) — and
        // with no page attached, no profile is advertised.
        $response = $this->request('/api/cursorGroups/1/relationships/widgets');
        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertCount(6, $data);
    }

    // --- forward / backward round-trips over the linkage ------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheParentScopedLinkageInFixedPages(): void
    {
        // Group 1 owns widgets 1,2,3,4,5,7. sort=priority,id asc (nulls last):
        //   10:(2,7) 20:(5) 30:(1,4) null:(3)
        self::assertSame(
            ['2', '7', '5', '1', '4', '3'],
            $this->walkForward('/api/cursorGroups/1/relationships/widgets?sort=priority,id', 2),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anotherParentsMembersNeverLeakIntoTheLinkagePage(): void
    {
        // Group 2 owns ONLY widgets 6 and 8. sort=priority,id: 20:(8) null:(6).
        self::assertSame(
            ['8', '6'],
            $this->walkForward('/api/cursorGroups/2/relationships/widgets?sort=priority,id', 1),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingViaPageBeforeEqualsTheForwardPages(): void
    {
        $forwardPages = $this->forwardPages('/api/cursorGroups/1/relationships/widgets?sort=priority,id', 2);
        self::assertGreaterThanOrEqual(3, \count($forwardPages));

        // The deepest page's `prev` carries a page[before] cursor; walking it back to
        // the head reproduces the forward pages exactly.
        self::assertSame(
            \array_map(static fn(array $page): array => $page['ids'], $forwardPages),
            $this->backwardPagesFrom($forwardPages[\count($forwardPages) - 1]['path']),
        );
    }

    // --- the linkage body stays links-only --------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theWindowedLinkageBodyStaysLinksOnly(): void
    {
        $response = $this->request('/api/cursorGroups/1/relationships/widgets?sort=priority,id&page[size]=2');
        $response->assertOk();

        $document = $response->json();
        self::assertIsArray($document);

        // Identifier-only members: `type` + `id`, never attributes/relationships.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertNotEmpty($data);
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertArrayNotHasKey('attributes', $identifier);
            self::assertArrayNotHasKey('relationships', $identifier);
        }

        // Links-only pagination: no `meta.page` on a linkage document (core ADR 0124).
        $meta = $document['meta'] ?? null;
        self::assertTrue(!\is_array($meta) || !\array_key_exists('page', $meta));

        // The cursor links are present — with never a `last` (no total exists).
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    // --- profile advertised through IdentifierResponse::withPage ----------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theWindowedLinkagePageAdvertisesThePublishedCursorPaginationProfile(): void
    {
        $response = $this->request('/api/cursorGroups/1/relationships/widgets?page[size]=2');
        $response->assertOk();

        // The attached page's profile rides the Content-Type `profile` parameter…
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

    // --- stale / malformed 400 ---------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aCursorReusedUnderADifferentSortColumnIsAStale400(): void
    {
        [, $links] = $this->page('/api/cursorGroups/1/relationships/widgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->request(\sprintf(
            '/api/cursorGroups/1/relationships/widgets?sort=category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        $response->assertStatus(400);
        $error = $this->firstError($response);
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
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
     * Walks forward from `$path` following `next` until exhausted, returning the
     * concatenated identifier ids in document order.
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
     * Fetches a windowed linkage page and returns `[ids, links]` (null links dropped).
     * Every member must be a bare `cursorWidgets` resource identifier.
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
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame('cursorWidgets', $identifier['type'] ?? null);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
            self::assertArrayNotHasKey('attributes', $identifier);
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
