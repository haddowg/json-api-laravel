<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The batched-include CURSOR (keyset) pagination acceptance suite, asserted
 * byte-identical on the in-memory ({@see InMemoryCursorIncludeConformanceTest}) and
 * Eloquent ({@see EloquentCursorIncludeConformanceTest}) providers over the shared
 * `cursorGroups` → `widgets` declaration (the relation declares its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator}, default size 2).
 *
 * An include carries no cursor token (the Relationship Queries profile pins the
 * included page to page 1), so a cursor-resolved include is always a FIRST cursor
 * page per parent: `EloquentDataProvider::fetchWindowedBatch` mints each parent's
 * forward cursor from its boundary row through the same per-parent keyset fetch the
 * related endpoint runs (docs/adr/0006 lifted), and the batcher renders a
 * {@see \haddowg\JsonApi\Pagination\CursorBasedPage} — the relationship object emits
 * `first`/`next` (the minted `page[after]`) and never `prev`/`last`. Group 1 owns
 * widgets `1,2,3,4,5,7`, so its first PK-only keyset page is `1,2` and its `next`
 * continues to `3,4`. Because `cursorGroups` itself is page-based, the document also
 * proves the watch-item: a cursor-resolved INCLUDE advertises the cursor-pagination
 * profile even when the primary collection does not.
 */
abstract class CursorIncludeConformanceTestCase extends Orchestra
{
    /** Negotiates the Relationship-Queries (windowing) profile that windows an included to-many to page 1. */
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

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
     * Registers the published cursor-pagination profile so a cursor-resolved include
     * advertises it, and sets the `/api` route prefix base URI.
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

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCursorResolvedIncludeRendersAFirstCursorPagePerParent(): void
    {
        $document = $this->includeDocument('/api/cursorGroups/1?include=widgets');
        $widgets = $this->relationshipObject($document['data'] ?? null, 'widgets');

        // Page 1 of the cursor-paginated relation: the two lowest-id widgets under the
        // PK-only keyset (size 2), byte-identical on both providers.
        self::assertSame(['1', '2'], $this->linkageIds($widgets));

        // A first cursor page: `first` and a `next` carrying the minted opaque cursor
        // token; `prev` and `last` are omitted (an include is always page 1, and a
        // cursor page has no total to locate a last page).
        $links = $widgets['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('page%5Bafter%5D=', $this->href($links['next']));
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aCursorResolvedIncludeAdvertisesTheCursorProfileAtTheDocumentLevel(): void
    {
        // cursorGroups is page-based, yet the cursor-resolved `widgets` include activates
        // the cursor-pagination profile — the document must advertise it on Content-Type
        // and jsonapi.profile even though the primary collection does not (the watch-item).
        $response = $this->request('/api/cursorGroups/1?include=widgets');
        $response->assertOk();

        self::assertStringContainsString(
            CursorPaginationProfile::URI,
            (string) $response->headers->get('Content-Type'),
        );

        $profiles = $response->json('jsonapi.profile');
        self::assertIsArray($profiles);
        self::assertContains(CursorPaginationProfile::URI, $profiles);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theMintedIncludeCursorContinuesCorrectlyOnTheRelationshipEndpoint(): void
    {
        // The `next` cursor an INCLUDE mints must be a real keyset boundary: following it
        // on the relationship-linkage endpoint yields the next page (`3,4`), proving the
        // per-parent boundary row was minted under the same keyset the endpoint continues
        // from — byte-identical on both providers.
        $document = $this->includeDocument('/api/cursorGroups/1?include=widgets');
        $links = $this->relationshipObject($document['data'] ?? null, 'widgets')['links'] ?? null;
        self::assertIsArray($links);
        $next = $this->href($links['next'] ?? null);

        $page2 = $this->request($this->relativePath($next))->json();
        self::assertIsArray($page2);
        $data = $page2['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $ids[] = $identifier['id'] ?? null;
        }

        self::assertSame(['3', '4'], $ids);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::PROFILE_ACCEPT]);
    }

    /**
     * Fetches `$path` under the Relationship-Queries profile and returns the decoded body.
     *
     * @return array<string, mixed>
     */
    private function includeDocument(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();

        $document = $response->json();
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param mixed $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipObject(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $object */
        return $object;
    }

    /**
     * @param array<string, mixed> $relationshipObject
     *
     * @return list<string>
     */
    private function linkageIds(array $relationshipObject): array
    {
        $data = $relationshipObject['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame('cursorWidgets', $identifier['type'] ?? null);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link) && \is_string($link['href'] ?? null)) {
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

        return $query !== null && $query !== false && $query !== '' ? $path . '?' . $query : $path;
    }
}
