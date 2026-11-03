<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sort families over HTTP, asserted identically on the in-memory witness
 * ({@see InMemorySortFamilyConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentSortFamilyConformanceTest}) over the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::sortCampaigns()} seed.
 *
 * `attr.<field>` resolves to a field sort and `value.<placeholder>` to a custom sort,
 * which the Eloquent provider runs by joining the placeholder value table. The `cap`
 * values mirror the cursor widgets' nullable `priority` (30, 10, null, 30, 20, null, 10,
 * 20 for ids 1 to 8) and `team` their `category`, so the cursor walks below match the
 * keyset cases of {@see CursorConformanceTestCase}.
 */
abstract class SortFamilyConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string CURSOR_CONTENT_TYPE = self::MEDIA_TYPE . '; profile="' . CursorPaginationProfile::URI . '"';

    /**
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

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- page (offset) ordering ------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyResolvingToAFieldSortOrdersByThatColumn(): void
    {
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $this->pageIds('/api/sortCampaigns?sort=attr.name'));
        self::assertSame(['8', '7', '6', '5', '4', '3', '2', '1'], $this->pageIds('/api/sortCampaigns?sort=-attr.name'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyResolvingToACustomSortOrdersByTheJoinedValue(): void
    {
        // A page orders by the store's native null placement: nulls first ascending, last
        // descending, the same on both providers.
        self::assertSame(['3', '6', '2', '7', '5', '8', '1', '4'], $this->pageIds('/api/sortCampaigns?sort=value.cap,id'));
        self::assertSame(['1', '4', '5', '8', '2', '7', '3', '6'], $this->pageIds('/api/sortCampaigns?sort=-value.cap,id'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyMixesWithFixedKeys(): void
    {
        self::assertSame(['7', '4', '2', '1', '8', '6', '5', '3'], $this->pageIds('/api/sortCampaigns?sort=attr.team,-id'));
        self::assertSame(['7', '2', '4', '1', '6', '3', '8', '5'], $this->pageIds('/api/sortCampaigns?sort=team,value.cap,-id'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function twoKeysOfOneFamilyOrderTogether(): void
    {
        self::assertSame(['6', '2', '7', '8', '4', '3', '5', '1'], $this->pageIds('/api/sortCampaigns?sort=value.reach,value.cap,id'));
        self::assertSame(['3', '5', '1', '2', '7', '8', '4', '6'], $this->walkForward('/api/sortCampaigns?sort=value.reach,value.cap', 3));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function pagePagingWalksAFamilySortInPages(): void
    {
        [$ids, $links] = $this->page('/api/sortCampaigns?sort=-value.cap,id&page[number]=2&page[size]=3', expectCursorProfile: false);

        self::assertSame(['8', '2', '7'], $ids);
        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('sort=-value.cap,id', \urldecode($this->href($links['next'])));
    }

    // --- 400s ------------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function anUnknownSuffixIsA400(): void
    {
        $this->assertUnrecognized('/api/sortCampaigns?sort=value.nope', 'value.nope');
        $this->assertUnrecognized('/api/sortCampaigns?sort=-attr.missing', 'attr.missing');
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aSuffixFailingTheFamilyPatternIsA400(): void
    {
        $this->assertUnrecognized('/api/sortCampaigns?sort=attr.Name', 'attr.Name');
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aBarePrefixIsA400(): void
    {
        $this->assertUnrecognized('/api/sortCampaigns?sort=value', 'value');
        $this->assertUnrecognized('/api/sortCampaigns?sort=value.', 'value.');
    }

    // --- cursor (keyset) paging ---------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagingWalksACustomSortForwardWithNullsLast(): void
    {
        self::assertSame(['2', '7', '5', '8', '1', '4', '3', '6'], $this->walkForward('/api/sortCampaigns?sort=value.cap', 3));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagingWalksADescendingCustomSortWithNullsFirst(): void
    {
        self::assertSame(['6', '3', '4', '1', '8', '5', '7', '2'], $this->walkForward('/api/sortCampaigns?sort=-value.cap', 3));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagingWalksACustomSortBelowAFixedKey(): void
    {
        self::assertSame(['4', '1', '7', '2', '6', '3', '8', '5'], $this->walkForward('/api/sortCampaigns?sort=team,-value.cap', 2));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagingWalksAFamilyFieldSort(): void
    {
        self::assertSame(['8', '7', '6', '5', '4', '3', '2', '1'], $this->walkForward('/api/sortCampaigns?sort=-attr.name', 3));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardCursorPagingEqualsTheForwardPages(): void
    {
        foreach (['value.cap', '-value.cap', 'team,-value.cap', '-value.cap,attr.name'] as $sort) {
            $forwardPages = $this->forwardPages('/api/sortCampaigns?sort=' . $sort, 2);
            self::assertCount(4, $forwardPages, $sort);

            self::assertSame(
                \array_map(static fn(array $page): array => $page['ids'], $forwardPages),
                $this->backwardPagesFrom($forwardPages[3]['path']),
                $sort,
            );
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aCursorReusedUnderAnotherFamilyKeyIsStale(): void
    {
        [, $links] = $this->page('/api/sortCampaigns?sort=value.cap&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->request(\sprintf('/api/sortCampaigns?sort=value.reach&page[size]=2&page[after]=%s', \rawurlencode($afterToken)));

        $response->assertStatus(400);
        self::assertSame('CURSOR_STALE', $this->firstError($response)['code'] ?? null);
    }

    // --- OpenAPI ---------------------------------------------------------------------

    #[Test]
    #[Group('openapi')]
    public function theOpenApiSortParameterListsTheFamilies(): void
    {
        $document = $this->app?->make(DocumentFactory::class)->forServer()->toArray();

        $sort = null;
        foreach ($this->arrayAt($document, 'paths', '/sortCampaigns', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'sort') {
                $sort = $parameter;
            }
        }

        self::assertSame([
            ['prefix' => 'attr', 'suffixPattern' => '[a-z]+'],
            ['prefix' => 'value', 'suffixPattern' => '[^,]+'],
        ], $this->at($sort, 'schema', 'x-sort-families'));
        self::assertSame(
            ['type' => 'string', 'enum' => ['id', '-id', 'name', '-name', 'team', '-team']],
            $this->at($sort, 'schema', 'items', 'anyOf', 0),
        );
        self::assertSame('^-?value\\.(?:[^,]+)$', $this->stringAt($sort, 'schema', 'items', 'anyOf', 2, 'pattern'));
    }

    // --- helpers ------------------------------------------------------------------

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    private function assertUnrecognized(string $path, string $key): void
    {
        $response = $this->request($path);
        $response->assertStatus(400);

        $error = $this->firstError($response);
        self::assertSame('SORTING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'sort'], $error['source'] ?? null);
        self::assertStringContainsString("'" . $key . "'", $this->stringAt($error, 'detail'));
    }

    /**
     * The ids of the whole collection on one count-based page.
     *
     * @return list<string>
     */
    private function pageIds(string $path): array
    {
        return $this->page($path . '&page[kind]=page&page[size]=10', expectCursorProfile: false)[0];
    }

    /**
     * @return list<string>
     */
    private function walkForward(string $path, int $size): array
    {
        $ids = [];
        foreach ($this->forwardPages($path, $size) as $page) {
            $ids = [...$ids, ...$page['ids']];
        }

        return $ids;
    }

    /**
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $path, int $size): array
    {
        $path .= '&page[size]=' . $size;

        $pages = [];
        $guard = 0;
        while (true) {
            [$ids, $links] = $this->page($path);
            $pages[] = ['ids' => $ids, 'path' => $path];
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(20, ++$guard, 'forward paging must terminate');
        }

        return $pages;
    }

    /**
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
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function page(string $path, bool $expectCursorProfile = true): array
    {
        $response = $this->request($path);
        $response->assertOk();
        $response->assertHeader('Content-Type', $expectCursorProfile ? self::CURSOR_CONTENT_TYPE : self::MEDIA_TYPE);

        $document = $response->json();
        self::assertIsArray($document);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('sortCampaigns', $resource['type'] ?? null);
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

    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

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
