<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters\ThroughFiltersServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `WhereThroughIn` and relationship-ending traversal paths over HTTP, asserted identically on
 * the in-memory witness ({@see InMemoryThroughFilterConformanceTest}) and the reference
 * Eloquent provider ({@see EloquentThroughFilterConformanceTest}) over the same
 * {@see ThroughFiltersServiceProvider} seed.
 *
 * Campaign 1 is advertiser 1's under the North brand, campaign 2 advertiser 2's under South
 * and campaign 3 advertiser 3's under North. Lead 1 is open on campaign 1 with template 1,
 * lead 2 closed on campaign 1 with templates 2 and 3, lead 3 open on campaign 2 with
 * template 3, lead 4 open on campaign 3 with none, and lead 5 closed on campaign 2 with
 * templates 1 and 2. Templates are served as `tpl-<key>`.
 */
abstract class ThroughFilterConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    protected const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    protected const string NORTH = ThroughFiltersServiceProvider::BRAND_NORTH;

    protected const string SOUTH = ThroughFiltersServiceProvider::BRAND_SOUTH;

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
        $config->set('through_filters.store', $this->store());

        return [JsonApiServiceProvider::class, ThroughFiltersServiceProvider::class];
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDelimitedListMatchesAnyRelatedId(): void
    {
        self::assertSame(['1', '2', '3', '5'], $this->ids('/api/leads?filter[advertiser]=1,2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anArrayListMatchesAnyRelatedId(): void
    {
        self::assertSame(['1', '2', '4'], $this->ids('/api/leads?filter[advertiser][]=1&filter[advertiser][]=3'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anIntegerIdListRejectsABadElementAtTheParameter(): void
    {
        $this->assertValueInvalid($this->request('/api/leads?filter[advertiser]=1,abc'), 'advertiser');
        $this->assertValueInvalid($this->request('/api/leads?filter[advertiser][]=1&filter[advertiser][]=abc'), 'advertiser');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aUuidIdListTakesUuidsAndRejectsAnythingElse(): void
    {
        self::assertSame(['3', '5'], $this->ids('/api/leads?filter[brand]=' . self::SOUTH));
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids('/api/leads?filter[brand][]=' . self::NORTH . '&filter[brand][]=' . self::SOUTH));

        $this->assertValueInvalid($this->request('/api/leads?filter[brand]=' . self::NORTH . ',not-a-uuid'), 'brand');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anEmptyListMatchesNothing(): void
    {
        self::assertSame([], $this->ids('/api/leads?filter[advertiser]='));
        self::assertSame([], $this->ids('/api/leads?filter[advertiser][]='));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPathEndingOnTheTypesOwnToOneComparesItsId(): void
    {
        self::assertSame(['3', '4', '5'], $this->ids('/api/leads?filter[campaign]=2,3'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPathEndingOnAToManyMatchesAnyMember(): void
    {
        self::assertSame(['1', '2', '5'], $this->ids('/api/leads?filter[template]=tpl-1,tpl-2'));
        self::assertSame(['2', '3'], $this->ids('/api/leads?filter[template][]=tpl-3'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anEncodedIdIsDecodedAndAnUndecodableOneIs400(): void
    {
        self::assertSame([], $this->ids('/api/leads?filter[template]=tpl-9'));

        $this->assertValueInvalid($this->request('/api/leads?filter[template]=tpl-1,1'), 'template');
        $this->assertValueInvalid($this->request('/api/leads?filter[template][]=template-1'), 'template');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aWhereThroughEndingOnARelationshipComparesOneId(): void
    {
        self::assertSame(['3', '5'], $this->ids('/api/leads?filter[advertiserIs]=2'));
        self::assertSame([], $this->ids('/api/leads?filter[advertiserIs]=9'));

        $this->assertValueInvalid($this->request('/api/leads?filter[advertiserIs]=two'), 'advertiserIs');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPathEndingOnAFieldComparesTheField(): void
    {
        self::assertSame(['3', '5'], $this->ids('/api/leads?filter[campaign.name]=Summer'));
        self::assertSame(['1', '2', '4'], $this->ids('/api/leads?filter[campaignNames][]=Spring&filter[campaignNames][]=Autumn,%20late'));
        $this->assertValueInvalid($this->request('/api/leads?filter[campaignNames]=Spring'), 'campaignNames');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aListInsideAWhereAllNarrowsWithItsSiblings(): void
    {
        self::assertSame(['1', '3'], $this->ids('/api/leads?filter[openFor]=1,2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aListInsideAWhereAnyWidensWithItsSiblings(): void
    {
        self::assertSame(['1', '2', '3', '5'], $this->ids('/api/leads?filter[reach]=1,' . self::SOUTH));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function twoTraversalsInOneGroupEachCorrelateOnTheirOwn(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/leads?filter[both]=1'));
        self::assertSame(['4'], $this->ids('/api/leads?filter[both]=3'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aWithCountCountsTheMembersAListThroughAPathKeeps(): void
    {
        self::assertSame(['1' => 2, '2' => 1, '3' => 0], $this->leadTotals('withCount=leads&relatedQuery[leads][filter][usesTemplate]=tpl-1,tpl-2'));
        self::assertSame(['1' => 1, '2' => 1, '3' => 0], $this->leadTotals('withCount=leads&relatedQuery[leads][filter][template][]=tpl-1'));
        self::assertSame(['1' => 0, '2' => 2, '3' => 0], $this->leadTotals('withCount=leads&relatedQuery[leads][filter][advertiserIs]=2'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelationshipQueryWindowTotalsTheMembersAListThroughAPathKeeps(): void
    {
        self::assertSame(['1' => 2, '2' => 1, '3' => 0], $this->leadTotals('include=leads&withCount=leads&relatedQuery[leads][filter][usesTemplate]=tpl-1,tpl-2'));
        self::assertSame(['1' => 0, '2' => 2, '3' => 0], $this->leadTotals('include=leads&withCount=leads&relatedQuery[leads][filter][advertiserIs]=2'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aFamilyResolvesToAListThroughARelationship(): void
    {
        self::assertSame(['1', '2', '4'], $this->ids('/api/leads?filter[via.advertiser]=1,3'));
        $this->assertValueInvalid($this->request('/api/leads?filter[via.advertiser]=1,abc'), 'via.advertiser');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function theRelatedEndpointResolvesAgainstTheRelatedType(): void
    {
        self::assertSame(['2'], $this->ids('/api/campaigns/1/leads?filter[template]=tpl-3'));
        self::assertSame(['5'], $this->ids('/api/campaigns/2/leads?filter[usesTemplate][]=tpl-1&filter[usesTemplate][]=tpl-2'));

        $this->assertValueInvalid($this->request('/api/campaigns/1/leads?filter[usesTemplate]=3'), 'usesTemplate');
        $this->assertValueInvalid($this->request('/api/campaigns/1/leads?filter[advertiser]=abc'), 'advertiser');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function theRelationshipEndpointResolvesAgainstTheRelatedType(): void
    {
        self::assertSame(['1'], $this->ids('/api/campaigns/1/relationships/leads?filter[usesTemplate]=tpl-1'));
        self::assertSame(['3', '5'], $this->ids('/api/campaigns/2/relationships/leads?filter[brand]=' . self::SOUTH));

        $this->assertValueInvalid($this->request('/api/campaigns/2/relationships/leads?filter[brand]=south'), 'brand');
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentTypesEachListByTheRelatedId(): void
    {
        $parameters = [];
        $document = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        foreach ($this->arrayAt($document, 'paths', '/leads', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null)) {
                $parameters[$parameter['name']] = $parameter;
            }
        }

        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $this->at($parameters, 'filter[advertiser]', 'schema'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $this->at($parameters, 'filter[advertiser][]', 'schema'));
        self::assertSame(['key' => 'advertiser', 'delimited' => true], $this->at($parameters, 'filter[advertiser][]', 'x-filter-list'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], $this->at($parameters, 'filter[brand]', 'schema'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $this->at($parameters, 'filter[template]', 'schema'));
        self::assertSame(['type' => 'integer'], $this->at($parameters, 'filter[advertiserIs]', 'schema'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $this->at($parameters, 'filter[campaignNames][]', 'schema'));
        self::assertArrayNotHasKey('filter[campaignNames]', $parameters);
    }

    // --- helpers ------------------------------------------------------------------

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * The sorted ids of a successful response's primary data.
     *
     * @return list<string>
     */
    protected function ids(string $path): array
    {
        $response = $this->request($path);
        $response->assertOk();

        $data = $response->json('data');
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
     * Each campaign's `leads` total under the Relationship Queries and Countable profiles.
     *
     * @return array<string, mixed>
     */
    protected function leadTotals(string $query): array
    {
        $response = $this->get('/api/campaigns?' . $query, ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . ' ' . self::COUNTABLE_PROFILE . '"']);
        $response->assertOk();

        $data = $response->json('data');
        self::assertIsArray($data);

        $totals = [];
        foreach ($data as $campaign) {
            self::assertIsArray($campaign);
            $id = $campaign['id'] ?? null;
            self::assertIsString($id);
            $totals[$id] = \data_get($campaign, 'relationships.leads.meta.total');
        }
        \ksort($totals);

        return $totals;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertValueInvalid(TestResponse $response, string $key): void
    {
        $response->assertStatus(400);
        self::assertSame('FILTER_VALUE_INVALID', $response->json('errors.0.code'));
        self::assertSame('filter[' . $key . ']', $response->json('errors.0.source.parameter'));
    }
}
