<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `?withCount` against collections paged by cursor, asserted identically on the in-memory
 * witness ({@see InMemoryCursorWithCountConformanceTest}) and the Eloquent provider
 * ({@see EloquentCursorWithCountConformanceTest}) over the shared cursor widgets, groups
 * and boards (the Laravel port of the bundle's `CursorWithCountConformanceTestCase`).
 *
 * A `_self_` count on a cursor page is the size of the filtered and scoped collection,
 * whatever page the cursor points at, and it renders only as the top-level `meta.total`:
 * a cursor page carries no `meta.page.total` and no `last` link.
 */
abstract class CursorWithCountConformanceTestCase extends Orchestra
{
    private const string COUNTABLE_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string WINDOWED_COUNTABLE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';

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

    // --- the primary collection -----------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:profiles')]
    public function withCountSelfRendersTheFilteredTotalOnACursorPage(): void
    {
        // The news widgets are 3, 5, 6 and 8. Sorted newest first, NULL counting as the
        // largest value: 6, 8, 3, 5.
        $document = $this->countedDocument('/api/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id');

        self::assertSame(['6', '8'], $this->ids($document));
        self::assertSame([
            'total' => 4,
            'page' => ['perPage' => 2, 'from' => '6', 'to' => '8', 'hasMore' => true],
        ], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
        self::assertContains(CountableProfile::URI, $this->appliedProfiles($document));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theTotalIgnoresTheCursorBoundsOnEveryPage(): void
    {
        $first = $this->countedDocument('/api/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id');
        $next = $this->links($first)['next'] ?? null;
        self::assertNotNull($next);

        $second = $this->countedDocument($this->relativePath($this->href($next)));
        self::assertSame(['3', '5'], $this->ids($second));
        self::assertSame(4, $this->meta($second)['total'] ?? null);
        self::assertSame(['perPage' => 2, 'from' => '3', 'to' => '5', 'hasMore' => false], $this->pageMeta($second));

        $prev = $this->links($second)['prev'] ?? null;
        self::assertNotNull($prev);
        $back = $this->countedDocument($this->relativePath($this->href($prev)));
        self::assertSame(['6', '8'], $this->ids($back));
        self::assertSame(4, $this->meta($back)['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfCountsTheWholeCollectionWithoutAFilter(): void
    {
        $document = $this->countedDocument('/api/cursorWidgets?withCount=_self_&page[size]=3');

        self::assertSame(8, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('total', $this->pageMeta($document));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCursorPageStaysCountFreeWhenNotAsked(): void
    {
        $document = $this->countedDocument('/api/cursorWidgets?page[size]=2&filter[category]=news');

        self::assertArrayNotHasKey('total', $this->meta($document));
    }

    // --- the related collection -----------------------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnARelatedCursorPageCountsInsideTheParentScope(): void
    {
        // Group 1 holds widgets 1, 2, 3, 4, 5 and 7, four of them guides; group 2 holds 6 and 8.
        $document = $this->countedDocument('/api/cursorGroups/1/widgets?withCount=_self_&page[size]=2&filter[category]=guide');
        self::assertSame(['1', '2'], $this->ids($document));
        self::assertSame([
            'total' => 4,
            'page' => ['perPage' => 2, 'from' => '1', 'to' => '2', 'hasMore' => true],
        ], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));

        $none = $this->countedDocument('/api/cursorGroups/2/widgets?withCount=_self_&page[size]=2&filter[category]=guide');
        self::assertSame([], $this->ids($none));
        self::assertSame(0, $this->meta($none)['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnAPivotRelatedCursorPageCountsInsideTheParentScope(): void
    {
        // Board 1 holds the same widgets as group 1; two of them are news.
        $document = $this->countedDocument('/api/cursorBoards/1/widgets?withCount=_self_&page[size]=1&filter[category]=news');

        self::assertSame(['3'], $this->ids($document));
        self::assertSame(2, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('total', $this->pageMeta($document));
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    // --- the relationship (linkage) endpoint ----------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnACursorLinkageRendersTheTotal(): void
    {
        $document = $this->countedDocument('/api/cursorGroups/1/relationships/widgets?withCount=_self_&page[size]=1&filter[category]=news');

        self::assertSame(['3'], $this->ids($document));
        self::assertSame(['total' => 2], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnAPivotCursorLinkageRendersTheTotal(): void
    {
        $document = $this->countedDocument('/api/cursorBoards/1/relationships/widgets?withCount=_self_&page[size]=3');

        self::assertCount(3, $this->ids($document));
        self::assertSame(6, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aCursorLinkageStaysCountFreeWhenNotAsked(): void
    {
        $document = $this->countedDocument('/api/cursorGroups/1/relationships/widgets?page[size]=2');

        self::assertArrayNotHasKey('meta', $document);
    }

    // --- a relation's count in its relationship object ------------------------

    #[Test]
    #[Group('spec:profiles')]
    public function aCursorPagedRelationCountsIntoItsRelationshipMeta(): void
    {
        $group = $this->countedDocument('/api/cursorGroups/1?withCount=widgets');
        self::assertSame(6, $this->meta($this->relationshipsOf($group['data'] ?? null)['widgets'])['total'] ?? null);

        $board = $this->countedDocument('/api/cursorBoards/2?withCount=widgets');
        self::assertSame(2, $this->meta($this->relationshipsOf($board['data'] ?? null)['widgets'])['total'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCursorWindowedIncludeCountsEachParentsWholeRelation(): void
    {
        $response = $this->get(
            '/api/cursorGroups?include=widgets&withCount=widgets&relatedQuery[widgets][sort]=priority',
            ['Accept' => self::WINDOWED_COUNTABLE_ACCEPT],
        );
        $response->assertOk();
        $document = $response->json();
        self::assertIsArray($document);

        $totals = [];
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $group) {
            self::assertIsArray($group);
            self::assertIsString($group['id'] ?? null);
            $widgets = $this->relationshipsOf($group)['widgets'];
            $totals[$group['id']] = $this->meta($widgets)['total'] ?? null;
            self::assertCount(2, \is_array($widgets['data'] ?? null) ? $widgets['data'] : [], 'the window is the cursor page of two');
            self::assertArrayNotHasKey('last', \is_array($widgets['links'] ?? null) ? $widgets['links'] : []);
        }

        self::assertSame(['1' => 6, '2' => 2], $totals);
    }

    // --- helpers ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function countedDocument(string $path): array
    {
        $response = $this->get($path, ['Accept' => self::COUNTABLE_ACCEPT]);
        $response->assertOk();
        $document = $response->json();
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function meta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $page = $this->meta($document)['page'] ?? [];
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function links(array $document): array
    {
        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return \array_filter($links, static fn(mixed $link): bool => $link !== null);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function appliedProfiles(array $document): array
    {
        $jsonapi = $document['jsonapi'] ?? [];
        self::assertIsArray($jsonapi);
        $profiles = $jsonapi['profile'] ?? [];
        self::assertIsArray($profiles);

        return \array_values($profiles);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function relationshipsOf(mixed $resource): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, array<string, mixed>> $relationships */
        return $relationships;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link)) {
            $link = $link['href'] ?? null;
        }
        self::assertIsString($link);

        return $link;
    }

    private function relativePath(string $url): string
    {
        $parts = \parse_url($url);
        self::assertIsArray($parts);

        return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
