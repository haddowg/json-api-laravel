<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cursor pages ordered by custom sorts over the six teams of
 * {@see \Workbench\App\Support\ConformanceFixtures::scoreTeams()}, asserted identically
 * on the in-memory witness and the reference Eloquent provider. On Eloquent the sorts are
 * a subquery whose value the cursor reads from the query, a bare `CASE` and a bare
 * `COALESCE`; in memory they are each sort's `keysetValue()`.
 *
 * Top scores run 1:9, 2:none, 3:7, 4:9, 5:none, 6:2. Tiers run 1:1, 2:2, 3:none,
 * 4:none, 5:2, 6:1. Points run 1:5, 2:4, 3:none, 4:8, 5:10, 6:1. A missing value sorts
 * last ascending and first descending, and the id tiebreak follows the last key.
 */
abstract class KeysetSortConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array ORDERS = [
        'topScore' => ['6', '3', '1', '4', '2', '5'],
        '-topScore' => ['5', '2', '4', '1', '3', '6'],
        'tier' => ['1', '6', '2', '5', '3', '4'],
        '-tier' => ['4', '3', '5', '2', '6', '1'],
        'points' => ['6', '2', '1', '4', '5', '3'],
        '-points' => ['3', '5', '4', '1', '2', '6'],
        'tier,-topScore' => ['1', '6', '5', '2', '4', '3'],
        '-topScore,tier' => ['2', '5', '1', '4', '3', '6'],
    ];

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

    /**
     * The collections holding all six teams. On Eloquent a league's teams page through
     * their pivot table.
     *
     * @return list<string>
     */
    protected function collectionPaths(): array
    {
        return ['/api/scoreTeams'];
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function theFirstTwoPagesFollowTheSort(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (self::ORDERS as $sort => $expected) {
                $pages = $this->forwardPages($collection, $sort, 2);

                self::assertSame(\array_slice($expected, 0, 2), $pages[0]['ids'], $collection . ' ' . $sort . ', page 1');
                self::assertSame(\array_slice($expected, 2, 2), $pages[1]['ids'] ?? null, $collection . ' ' . $sort . ', page 2');
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkTheSortForwards(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (self::ORDERS as $sort => $expected) {
                foreach ([1, 2, 4] as $size) {
                    self::assertSame($expected, \array_merge(...\array_column($this->forwardPages($collection, $sort, $size), 'ids')), $collection . ' ' . $sort . ', page size ' . $size);
                }
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkTheSortBackwards(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (\array_keys(self::ORDERS) as $sort) {
                foreach ([1, 2, 4] as $size) {
                    $forward = $this->forwardPages($collection, $sort, $size);

                    $backward = [];
                    $path = $forward[\count($forward) - 1]['path'];
                    while (true) {
                        [$ids, $links] = $this->page($path);
                        $backward[] = $ids;
                        if (!isset($links['prev'])) {
                            break;
                        }
                        $path = $this->relativePath($links['prev']);
                        self::assertLessThan(10, \count($backward), 'backward paging must terminate');
                    }

                    self::assertSame(\array_column($forward, 'ids'), \array_reverse($backward), $collection . ' ' . $sort . ', page size ' . $size);
                }
            }
        }
    }

    /**
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $collection, string $sort, int $size): array
    {
        $path = $collection . '?sort=' . $sort . '&page[size]=' . $size;

        $pages = [];
        while (true) {
            [$ids, $links] = $this->page($path);
            $pages[] = ['ids' => $ids, 'path' => $path];
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($links['next']);
            self::assertLessThan(10, \count($pages), 'forward paging must terminate');
        }

        return $pages;
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function page(string $path): array
    {
        $response = $this->get($path, ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $response->assertHeader('Content-Type', self::MEDIA_TYPE . '; profile="' . CursorPaginationProfile::URI . '"');

        $document = $response->json();
        self::assertIsArray($document);

        $links = [];
        foreach (\is_array($document['links'] ?? null) ? $document['links'] : [] as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            if (\is_string($href)) {
                $links[(string) $name] = $href;
            }
        }

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('scoreTeams', $resource['type'] ?? null);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }

        return [$ids, $links];
    }

    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
