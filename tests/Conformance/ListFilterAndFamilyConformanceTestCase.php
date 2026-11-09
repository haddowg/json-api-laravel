<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies\FilterFamiliesServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * List filters and filter families over HTTP, asserted identically on the in-memory witness
 * ({@see InMemoryListFilterAndFamilyConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentListFilterAndFamilyConformanceTest}) over the same
 * {@see FilterFamiliesServiceProvider::books()} seed, on every path a filter reaches: the
 * collection, a cursor walk, the related and relationship endpoints, a `relatedQuery`
 * include and count, and a collection action.
 *
 * Shelf 1 holds 1 "Hello, World" fiction 120, 2 Dune scifi 600 and 3 Emma classic 400; shelf
 * 2 holds 4 Hyperion scifi 480, 5 Persuasion classic 250 and 6 Neuromancer scifi 270.
 */
abstract class ListFilterAndFamilyConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    protected const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('filter_families.store', $this->store());

        return [JsonApiServiceProvider::class, FilterFamiliesServiceProvider::class];
    }

    // --- list filters ----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aListFilterTakesTheArrayForm(): void
    {
        self::assertSame(['2', '3', '4', '5', '6'], $this->ids('/api/books?filter[genre][]=scifi&filter[genre][]=classic'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDelimitedListTakesTheCommaForm(): void
    {
        self::assertSame(['1', '2', '4', '6'], $this->ids('/api/books?filter[genre]=scifi,%20fiction'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anArrayOnlyListKeepsACommaInsideAMember(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/books?filter[titles][]=Hello,%20World&filter[titles][]=Dune'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anArrayOnlyListGivenAStringIs400(): void
    {
        $this->assertValueInvalid($this->request('/api/books?filter[titles]=Dune'), 'titles');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aListMemberKeyedByNameIs400(): void
    {
        $this->assertValueInvalid($this->request('/api/books?filter[genre][first]=scifi'), 'genre');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function eachListMemberIsValidatedOnItsOwn(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/books?filter[pageCounts][]=120&filter[pageCounts][]=600'));
        self::assertSame(['1', '2'], $this->ids('/api/books?filter[pageCounts]=120,600'));

        $this->assertValueInvalid($this->request('/api/books?filter[pageCounts][]=120&filter[pageCounts][]=lots'), 'pageCounts');
        $this->assertValueInvalid($this->request('/api/books?filter[pageCounts]=120,lots'), 'pageCounts');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-pagination')]
    public function aCursorWalkKeepsTheListFilter(): void
    {
        $path = '/api/books?filter[in.genre][]=scifi&filter[in.genre][]=classic&page[kind]=cursor&page[size]=2';

        $ids = [];
        $guard = 0;
        while ($path !== null) {
            $response = $this->request($path);
            $response->assertOk();
            $ids = [...$ids, ...$this->dataIds($response)];
            $next = $response->json('links.next');
            $next = \is_array($next) ? ($next['href'] ?? null) : $next;
            $path = \is_string($next) ? $this->relativePath($next) : null;
            self::assertLessThan(10, ++$guard, 'cursor paging must terminate');
        }

        \sort($ids);
        self::assertSame(['2', '3', '4', '5', '6'], $ids);
    }

    // --- filter families -------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFamilyResolvesToAScalarFilter(): void
    {
        self::assertSame(['3', '5'], $this->ids('/api/books?filter[attr.genre]=classic'));
        self::assertSame(['4'], $this->ids('/api/books?filter[attr.pages]=480'));
        self::assertSame(['2'], $this->ids('/api/books?filter[attr.genre]=scifi&filter[pageCounts]=600,120'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aListFamilyResolvesToAListFilter(): void
    {
        self::assertSame(['1', '3', '5'], $this->ids('/api/books?filter[in.genre][]=fiction&filter[in.genre][]=classic'));
        self::assertSame(['2', '3'], $this->ids('/api/books?filter[in.title]=Dune,Emma'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aFamilyKeyIsValidatedByTheResolvedFiltersRules(): void
    {
        $this->assertValueInvalid($this->request('/api/books?filter[attr.pages]=lots'), 'attr.pages');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anExactKeyWinsOverAFamily(): void
    {
        // The exact `attr.title` is a substring match; the family would compare for equality.
        self::assertSame(['1'], $this->ids('/api/books?filter[attr.title]=ell'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnknownFamilySuffixIs400(): void
    {
        $this->assertUnrecognized($this->request('/api/books?filter[attr.colour]=red'), 'attr.colour');
        $this->assertUnrecognized($this->request('/api/books?filter[attr.Genre]=scifi'), 'attr.Genre');
        $this->assertUnrecognized($this->request('/api/books?filter[in.pages][]=120'), 'in.pages');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aBarePrefixIs400(): void
    {
        $this->assertUnrecognized($this->request('/api/books?filter[attr]=scifi'), 'attr');
        $this->assertUnrecognized($this->request('/api/books?filter[attr.]=scifi'), 'attr.');
        $this->assertUnrecognized($this->request('/api/books?filter[in][]=scifi'), 'in');
    }

    // --- related and relationship endpoints -----------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointTakesFamiliesAndListFilters(): void
    {
        self::assertSame(['4', '6'], $this->ids('/api/shelves/2/books?filter[attr.genre]=scifi'));
        self::assertSame(['1', '2'], $this->ids('/api/shelves/1/books?filter[genre][]=scifi&filter[genre][]=fiction'));
        self::assertSame(['1'], $this->ids('/api/shelves/1/books?filter[titles][]=Hello,%20World'));

        $this->assertUnrecognized($this->request('/api/shelves/1/books?filter[attr.colour]=red'), 'attr.colour');
        $this->assertValueInvalid($this->request('/api/shelves/1/books?filter[titles]=Dune'), 'titles');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointTakesFamiliesAndListFilters(): void
    {
        self::assertSame(['4', '5', '6'], $this->ids('/api/shelves/2/relationships/books?filter[in.genre]=classic,scifi'));
        self::assertSame(['5'], $this->ids('/api/shelves/2/relationships/books?filter[genre][]=classic'));
        self::assertSame(['6'], $this->ids('/api/shelves/2/relationships/books?filter[attr.pages]=270'));

        $this->assertValueInvalid($this->request('/api/shelves/2/relationships/books?filter[pageCounts][]=lots'), 'pageCounts');
    }

    // --- relationship queries ------------------------------------------------

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aFamilyFiltersARelatedQueryInclude(): void
    {
        $response = $this->get('/api/shelves?include=books&relatedQuery[books][filter][attr.genre]=scifi', $this->profileHeaders(self::RELATIONSHIP_QUERIES_PROFILE));
        $response->assertOk();

        self::assertSame(['1' => ['2'], '2' => ['4', '6']], $this->linkage($response, 'books'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aListFamilyFiltersARelatedQueryInclude(): void
    {
        $response = $this->get('/api/shelves/1?include=books&relatedQuery[books][filter][in.title][]=Hello,%20World&relatedQuery[books][filter][in.title][]=Emma', $this->profileHeaders(self::RELATIONSHIP_QUERIES_PROFILE));
        $response->assertOk();

        // The array form keeps each member whole, so a delimited list's comma stays in the title.
        self::assertSame(['1', '3'], $this->idsOf($response->json('data.relationships.books.data')));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aFamilyNarrowsARelatedQueryCount(): void
    {
        $response = $this->get(
            '/api/shelves?include=books&withCount=books&relatedQuery[books][filter][attr.genre]=classic',
            $this->profileHeaders(self::RELATIONSHIP_QUERIES_PROFILE . ' ' . self::COUNTABLE_PROFILE),
        );
        $response->assertOk();

        $totals = [];
        foreach ($this->resources($response) as $shelf) {
            $id = $shelf['id'] ?? null;
            self::assertIsString($id);
            $totals[$id] = \data_get($shelf, 'relationships.books.meta.total');
        }
        \ksort($totals);

        self::assertSame(['1' => 1, '2' => 1], $totals);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownFamilySuffixInARelatedQueryIs400(): void
    {
        $response = $this->get('/api/shelves?include=books&relatedQuery[books][filter][attr.colour]=red', $this->profileHeaders(self::RELATIONSHIP_QUERIES_PROFILE));

        $response->assertStatus(400);
        self::assertSame('FILTERING_UNRECOGNIZED', $response->json('errors.0.code'));
    }

    // --- collection actions ----------------------------------------------------

    #[Test]
    #[Group('actions')]
    public function aCollectionActionTakesListFiltersAndFamilies(): void
    {
        self::assertSame(['2', '4', '6'], $this->actionIds('?filter[genre][]=scifi'));
        self::assertSame(['2'], $this->actionIds('?filter[genre]=scifi&filter[attr.pages]=600'));
        self::assertSame(['1', '3'], $this->actionIds('?filter[titles][]=Hello,%20World&filter[titles][]=Emma'));
        self::assertSame(['3'], $this->actionIds('?filter[in.title][]=Emma&filter[titles][]=Hello,%20World&filter[titles][]=Emma'));

        $this->assertUnrecognized($this->request('/api/books/-actions/count?filter[attr.colour]=red'), 'attr.colour');
        $this->assertUnrecognized($this->request('/api/books/-actions/count?filter[in]=scifi'), 'in');
        $this->assertValueInvalid($this->request('/api/books/-actions/count?filter[titles]=Dune'), 'titles');
        $this->assertValueInvalid($this->request('/api/books/-actions/count?filter[attr.pages]=lots'), 'attr.pages');
    }

    // --- OpenAPI ---------------------------------------------------------------

    #[Test]
    #[Group('openapi')]
    public function theDocumentDescribesTheListsAndFamilies(): void
    {
        $document = $this->resolve(DocumentFactory::class)->forServer()->toArray();

        $names = [];
        $families = null;
        foreach ($this->arrayAt($document, 'paths', '/books', 'get', 'parameters') as $parameter) {
            if (!\is_array($parameter) || !\is_string($parameter['name'] ?? null)) {
                continue;
            }
            $names[] = $parameter['name'];
            if ($parameter['name'] === 'filter') {
                $families = $this->at($parameter, 'schema', 'x-filter-families');
            }
        }

        self::assertContains('filter[genre]', $names);
        self::assertContains('filter[genre][]', $names);
        self::assertContains('filter[titles][]', $names);
        self::assertNotContains('filter[titles]', $names);
        self::assertIsArray($families);
        self::assertSame(['attr', 'in'], \array_column($families, 'prefix'));
    }

    // --- helpers ------------------------------------------------------------------

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return array<string, string>
     */
    private function profileHeaders(string $profiles): array
    {
        return ['Accept' => self::MEDIA_TYPE . ';profile="' . $profiles . '"'];
    }

    /**
     * The sorted ids of a successful response's primary data.
     *
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();

        $ids = $this->dataIds($response);
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function actionIds(string $query): array
    {
        $response = $this->request('/api/books/-actions/count' . $query);
        $response->assertOk();

        $ids = $response->json('meta.ids');
        self::assertIsArray($ids);
        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', \array_values($ids));
        \sort($ids);

        return $ids;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function dataIds(TestResponse $response): array
    {
        return $this->idsOf($response->json('data'));
    }

    /**
     * @return list<string>
     */
    private function idsOf(mixed $data): array
    {
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<array<array-key, mixed>>
     */
    private function resources(TestResponse $response): array
    {
        $data = $response->json('data');
        self::assertIsArray($data);

        $resources = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * Each primary resource's linkage ids for `$relationship`, keyed by the resource id.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, list<string>>
     */
    private function linkage(TestResponse $response, string $relationship): array
    {
        $linkage = [];
        foreach ($this->resources($response) as $resource) {
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $linkage[$id] = $this->idsOf(\data_get($resource, 'relationships.' . $relationship . '.data'));
        }
        \ksort($linkage);

        return $linkage;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertValueInvalid(TestResponse $response, string $key): void
    {
        $response->assertStatus(400);
        self::assertSame('FILTER_VALUE_INVALID', $response->json('errors.0.code'));
        self::assertSame('filter[' . $key . ']', $response->json('errors.0.source.parameter'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertUnrecognized(TestResponse $response, string $key): void
    {
        $response->assertStatus(400);
        self::assertSame('FILTERING_UNRECOGNIZED', $response->json('errors.0.code'));
        $detail = $response->json('errors.0.detail');
        self::assertIsString($detail);
        self::assertStringContainsString("'" . $key . "'", $detail);
    }

    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
