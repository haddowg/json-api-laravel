<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use Composer\InstalledVersions;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * The pagination-menu witness, run on both provider arms: `TrackResource::pagination()`
 * offers page, cursor and offset strategies on `GET /tracks`, with page as the default.
 * A client names one with `page[kind]`, or lets a key only one strategy reads
 * (`after`/`before`, `offset`/`limit`) pick it. No `page`, or only the shared `size`,
 * keeps the page strategy, so every request written before the menu existed gets the
 * same response.
 *
 * The fixtures seed three tracks (1, 2, 3), none explicit, so every walk below must
 * visit all three, in id order, once, without leaving the strategy it started on.
 *
 * When a request names its strategy with `page[kind]`, every pagination link must carry
 * it too. Core 1.1.1 drops it (and every other request `page` member) when it builds the
 * links, so the cursor `first` link, which is only `page[size]`, falls back to the page
 * default. The tests that pin the fixed behaviour skip until core 1.2.0 is installed.
 *
 * @internal
 */
#[CoversNothing]
abstract class PaginationMenuTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array ALL_TRACKS = ['1', '2', '3'];

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
    }

    public function test_no_page_parameter_uses_the_page_default(): void
    {
        $response = $this->page('/api/tracks', cursor: false);

        self::assertSame(self::ALL_TRACKS, $this->ids($response));
        self::assertSame(
            ['currentPage' => 1, 'perPage' => 15, 'from' => 1, 'to' => 3],
            $response->json('meta.page'),
        );
        self::assertSame('/api/tracks?page[number]=1&page[size]=15', $this->link($response, 'self'));
    }

    public function test_page_kind_selects_the_page_strategy(): void
    {
        $response = $this->page('/api/tracks?page[kind]=page&page[size]=2', cursor: false);

        self::assertSame(['1', '2'], $this->ids($response));
        $response->assertJsonPath('meta.page.currentPage', 1);
        self::assertSame(['number' => '2', 'size' => '2'], $this->pageQuery($this->link($response, 'next'), withoutKind: true));
    }

    public function test_cursor_kind_selects_the_cursor_strategy(): void
    {
        $response = $this->page('/api/tracks?page[kind]=cursor&page[size]=2', cursor: true);

        self::assertSame(['1', '2'], $this->ids($response));
        $response->assertJsonPath('meta.page.hasMore', true);
        self::assertSame(['after', 'size'], \array_keys($this->pageQuery($this->link($response, 'next'), withoutKind: true)));
    }

    public function test_offset_kind_selects_the_offset_strategy(): void
    {
        // `size` belongs to page and cursor, not offset, so the offset arm ignores it and
        // uses its own default limit.
        $response = $this->page('/api/tracks?page[kind]=offset&page[size]=2', cursor: false);

        self::assertSame(self::ALL_TRACKS, $this->ids($response));
        $response->assertJsonPath('meta.page.offset', 0);
        self::assertSame(['offset' => '0', 'limit' => '15'], $this->pageQuery($this->link($response, 'self'), withoutKind: true));
    }

    public function test_a_cursor_only_key_selects_cursor_without_a_kind(): void
    {
        $first = $this->page('/api/tracks?page[kind]=cursor&page[size]=2', cursor: true);
        $after = $this->pageQuery($this->link($first, 'next'))['after'] ?? null;
        self::assertIsString($after);

        $response = $this->page('/api/tracks?page[after]=' . $after . '&page[size]=2', cursor: true);

        self::assertSame(['3'], $this->ids($response));
        $response->assertJsonPath('meta.page.hasMore', false);
    }

    public function test_an_offset_only_key_selects_offset_without_a_kind(): void
    {
        $response = $this->page('/api/tracks?page[offset]=1&page[limit]=1', cursor: false);

        self::assertSame(['2'], $this->ids($response));
        self::assertSame(['offset' => 1, 'limit' => 1, 'from' => 2, 'to' => 2], $response->json('meta.page'));
    }

    public function test_a_shared_key_alone_keeps_the_page_default(): void
    {
        // `size` is read by page and cursor alike, so it can't pick between them.
        $response = $this->page('/api/tracks?page[size]=2', cursor: false);

        self::assertSame(['1', '2'], $this->ids($response));
        $response->assertJsonPath('meta.page.currentPage', 1);
    }

    public function test_an_unknown_kind_is_a_400(): void
    {
        $response = $this->get('/api/tracks?page[kind]=keyset', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.0.code', 'PAGINATION_KIND_UNKNOWN');
        $response->assertJsonPath('errors.0.source', ['parameter' => 'page[kind]']);
    }

    public function test_the_page_links_walk_the_whole_collection(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/api/tracks?page[kind]=page&page[size]=2', 'next', 'page'));
        self::assertSame(self::ALL_TRACKS, $this->walk('/api/tracks?page[size]=2', 'next', 'page'));
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk('/api/tracks?page[number]=2&page[size]=2', 'prev', 'page'));
    }

    public function test_the_cursor_links_walk_the_whole_collection_both_ways(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/api/tracks?page[kind]=cursor&page[size]=1', 'next', 'cursor'));

        // Walk forward to the last page, then follow `prev` back to the start.
        $path = '/api/tracks?page[kind]=cursor&page[size]=1';
        for ($i = 0; $i < 2; ++$i) {
            $path = $this->link($this->page($path, cursor: true), 'next');
        }
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk($path, 'prev', 'cursor'));
    }

    public function test_the_offset_links_walk_the_whole_collection(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/api/tracks?page[kind]=offset&page[limit]=2', 'next', 'offset'));
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk('/api/tracks?page[offset]=2&page[limit]=2', 'prev', 'offset'));
    }

    public function test_page_links_carry_the_named_kind(): void
    {
        $this->assertEveryLinkCarriesTheKind('page', '/api/tracks?page[kind]=page&page[number]=2&page[size]=1');
    }

    public function test_cursor_links_carry_the_named_kind(): void
    {
        $this->assertEveryLinkCarriesTheKind('cursor', '/api/tracks?page[kind]=cursor&page[size]=1');
    }

    public function test_offset_links_carry_the_named_kind(): void
    {
        $this->assertEveryLinkCarriesTheKind('offset', '/api/tracks?page[kind]=offset&page[offset]=1&page[limit]=1');
    }

    public function test_the_cursor_first_link_stays_on_cursor(): void
    {
        self::requireMenuLinkFix();

        $first = $this->page('/api/tracks?page[kind]=cursor&page[size]=1', cursor: true);
        $second = $this->page($this->link($first, 'next'), cursor: true);

        $response = $this->page($this->link($second, 'first'), cursor: true);

        self::assertSame(['1'], $this->ids($response));
        self::assertNotNull($response->json('meta.page.hasMore'));
    }

    public function test_every_strategy_keeps_the_page_size_cap(): void
    {
        $cases = [
            ['page[size]=1000000', 'perPage', false],
            ['page[kind]=cursor&page[size]=1000000', 'perPage', true],
            ['page[limit]=1000000', 'limit', false],
        ];
        foreach ($cases as [$query, $key, $cursor]) {
            $this->page('/api/tracks?' . $query, $cursor)->assertJsonPath('meta.page.' . $key, CatalogConfig::MAX_PER_PAGE);
        }
    }

    /**
     * Follows `$rel` links from `$path` until there are none, collecting the ids of each
     * page and checking every page came from `$strategy`. A `prev` walk collects each
     * page reversed, so the result reads last-first.
     *
     * @return list<string>
     */
    private function walk(string $path, string $rel, string $strategy): array
    {
        $ids = [];
        for ($guard = 0; $guard < 10; ++$guard) {
            $response = $this->page($path, cursor: $strategy === 'cursor');
            self::assertNotNull($response->json(match ($strategy) {
                'cursor' => 'meta.page.hasMore',
                'page' => 'meta.page.currentPage',
                default => 'meta.page.offset',
            }), "a {$strategy} page");
            $pageIds = $this->ids($response);
            \array_push($ids, ...($rel === 'prev' ? \array_reverse($pageIds) : $pageIds));

            if ($response->json('links.' . $rel) === null) {
                return $ids;
            }
            $path = $this->link($response, $rel);
        }

        self::fail("Following '{$rel}' from {$path} did not terminate.");
    }

    private function assertEveryLinkCarriesTheKind(string $kind, string $uri): void
    {
        self::requireMenuLinkFix();

        $response = $this->page($uri, cursor: $kind === 'cursor');
        $links = (array) $response->json('links');
        unset($links['describedby']);
        self::assertNotEmpty($links);

        foreach (\array_keys($links) as $rel) {
            self::assertIsString($rel);
            self::assertSame($kind, $this->pageQuery($this->link($response, $rel))['kind'] ?? null, "links.{$rel}");
        }
    }

    /**
     * Core 1.1.1 drops `page[kind]` from a menu's pagination links; 1.2.0 keeps it.
     */
    private static function requireMenuLinkFix(): void
    {
        if (\version_compare(InstalledVersions::getVersion('haddowg/json-api') ?? '0', '1.2.0', '<')) {
            self::markTestSkipped('needs haddowg/json-api >= 1.2.0: menu links keep page[kind]');
        }
    }

    /**
     * The `page` members of a link's query.
     *
     * @return array<string, mixed>
     */
    private function pageQuery(string $link, bool $withoutKind = false): array
    {
        \parse_str((string) \parse_url($link, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? [];
        self::assertIsArray($page);
        if ($withoutKind) {
            unset($page['kind']);
        }

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * Fetches a page, asserting a 200 and whether the cursor-pagination profile is
     * advertised (a cursor page does, a page or offset page does not).
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function page(string $uri, bool $cursor): TestResponse
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();

        $contentType = (string) $response->headers->get('Content-Type');
        self::assertStringStartsWith(self::MEDIA_TYPE, $contentType);
        if ($cursor) {
            self::assertStringContainsString(CursorPaginationProfile::URI, $contentType);
        } else {
            self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        }

        return $response;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function ids(TestResponse $response): array
    {
        $ids = [];
        foreach ((array) $response->json('data') as $resource) {
            self::assertIsArray($resource);
            self::assertSame('tracks', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The named top-level link as a decoded path + query, ready to re-issue.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function link(TestResponse $response, string $rel): string
    {
        $href = $response->json('links.' . $rel);
        self::assertIsString($href, "links.{$rel}");

        $path = (string) \parse_url($href, \PHP_URL_PATH);
        $query = \parse_url($href, \PHP_URL_QUERY);

        return \is_string($query) ? $path . '?' . \urldecode($query) : $path;
    }
}
