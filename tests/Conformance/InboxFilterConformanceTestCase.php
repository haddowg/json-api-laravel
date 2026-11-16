<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\Inbox\InboxServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `WhereSet` and `WhereMorph` over HTTP, asserted identically on the in-memory witness
 * ({@see InMemoryInboxFilterConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentInboxFilterConformanceTest}) over the inbox {@see InboxServiceProvider}
 * seeds.
 */
abstract class InboxFilterConformanceTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    protected const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

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
        $config->set('inbox.store', $this->store());

        return [JsonApiServiceProvider::class, InboxServiceProvider::class];
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function archivedItemsAreLeftOutByDefault(): void
    {
        self::assertSame(['1', '2', '5'], $this->ids('/api/inbItems'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aClientAsksForArchivedOnlyOrForBoth(): void
    {
        self::assertSame(['3', '4'], $this->ids('/api/inbItems?filter[archived]=true'));
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids('/api/inbItems?filter[archived]=true,false'));
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids('/api/inbItems?filter[archived][]=true&filter[archived][]=false'));
        self::assertSame(['1', '2', '5'], $this->ids('/api/inbItems?filter[archived]=false'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSecondSetFilterNarrowsAlongsideTheDefault(): void
    {
        self::assertSame(['2'], $this->ids('/api/inbItems?filter[read]=true'));
        self::assertSame(['2', '4'], $this->ids('/api/inbItems?filter[read]=1&filter[archived]=true,false'));
        self::assertSame(['1', '3', '5'], $this->ids('/api/inbItems?filter[read]=no&filter[archived]=yes,no'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aValueThatIsNotABooleanIsRejected(): void
    {
        $this->assertValueInvalid('filter[archived]=banana', 'filter[archived]');
        $this->assertValueInvalid('filter[archived]=true,maybe', 'filter[archived]');
        $this->assertValueInvalid('filter[archived][x]=true', 'filter[archived]');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTypeAloneMatchesEveryItemAboutThatType(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/inbItems?filter[subject][type]=inbRfps'));
        self::assertSame(['3', '4'], $this->ids('/api/inbItems?filter[subject][type]=inbCampaigns&filter[archived]=true,false'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function idsNarrowInEitherForm(): void
    {
        self::assertSame(['1'], $this->ids('/api/inbItems?filter[subject][type]=inbRfps&filter[subject][id]=812'));
        self::assertSame(['1', '2'], $this->ids('/api/inbItems?filter[subject][type]=inbRfps&filter[subject][id]=812,813'));
        self::assertSame(['2'], $this->ids('/api/inbItems?filter[subject][type]=inbRfps&filter[subject][id][]=813'));
        self::assertSame([], $this->ids('/api/inbItems?filter[subject][type]=inbRfps&filter[subject][id]=814'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anIdIsDecodedThroughItsTypesEncoder(): void
    {
        self::assertSame(['3'], $this->ids('/api/inbItems?filter[subject][type]=inbCampaigns&filter[subject][id]=cmp-1&filter[archived]=true,false'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMalformedMorphValueIsRejectedAtItsMember(): void
    {
        $malformed = [
            'filter[subject]=inbRfps' => 'filter[subject]',
            'filter[subject][inbRfps]=812' => 'filter[subject]',
            'filter[subject][id]=812' => 'filter[subject][type]',
            'filter[subject][type]=inbFolders' => 'filter[subject][type]',
            'filter[subject][type][]=inbRfps&filter[subject][type][]=inbCampaigns' => 'filter[subject][type]',
            'filter[subject][type]=inbRfps&filter[subject][id]=abc' => 'filter[subject][id]',
            'filter[subject][type]=inbCampaigns&filter[subject][id]=1' => 'filter[subject][id]',
            'filter[subject][type]=inbRfps&filter[subject][id]=812,' => 'filter[subject][id]',
        ];

        foreach ($malformed as $query => $parameter) {
            $this->assertValueInvalid($query, $parameter);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToManyMatchesAnItemWithAMemberOfThatType(): void
    {
        self::assertSame(['1', '3'], $this->ids('/api/inbItems?filter[linked][type]=inbRfps&filter[archived]=true,false'));
        self::assertSame(['3'], $this->ids('/api/inbItems?filter[linked][type]=inbCampaigns&filter[linked][id]=cmp-2&filter[archived]=true,false'));
        self::assertSame(['2', '3'], $this->ids('/api/inbItems?filter[linked][type]=inbCampaigns&filter[archived]=true,false'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRelatedEndpointAppliesTheDefaultUnlessTheRelationDropsIt(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/inbFolders/1/items'));
        self::assertSame(['3'], $this->ids('/api/inbFolders/1/items?filter[archived]=true'));
        self::assertSame(['1', '2', '3'], $this->ids('/api/inbFolders/1/everything'));
        self::assertSame(['1'], $this->ids('/api/inbFolders/1/everything?filter[subject][type]=inbRfps&filter[subject][id]=812'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function relatedQueryFiltersEachParentsItems(): void
    {
        $response = $this->get(
            '/api/inbFolders?include=items&relatedQuery[items][filter][archived]=true&relatedQuery[items][filter][subject][type]=inbCampaigns',
            ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"'],
        );
        $response->assertOk();

        $linkage = [];
        foreach ($this->arrayAt($response->json(), 'data') as $folder) {
            self::assertIsArray($folder);
            $id = $folder['id'] ?? null;
            self::assertIsString($id);
            $linkage[$id] = \array_column($this->arrayAt($folder, 'relationships', 'items', 'data'), 'id');
        }
        \ksort($linkage);

        self::assertSame(['1' => ['3'], '2' => ['4']], $linkage);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aCollectionActionTakesBothFilters(): void
    {
        self::assertSame(['1', '2', '5'], $this->tally(''));
        self::assertSame(['1', '2'], $this->tally('?filter[subject][type]=inbRfps'));
        self::assertSame(['4'], $this->tally('?filter[subject][type]=inbCampaigns&filter[read]=true&filter[archived]=true'));

        $this->request('/api/inbItems/-actions/tally?filter[subject][type]=nope')->assertStatus(400);
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentDescribesBothFilters(): void
    {
        $parameters = [];
        $document = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        foreach ($this->arrayAt($document, 'paths', '/inbItems', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null)) {
                $parameters[$parameter['name']] = $parameter;
            }
        }

        self::assertSame(['type' => 'array', 'default' => [false], 'items' => ['type' => 'boolean']], $this->at($parameters, 'filter[archived]', 'schema'));
        self::assertSame(['key' => 'archived', 'delimited' => true], $this->at($parameters, 'filter[archived][]', 'x-filter-list'));
        self::assertSame('deepObject', $this->at($parameters, 'filter[subject]', 'style'));
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['inbRfps', 'inbCampaigns']],
                'id' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ], $this->at($parameters, 'filter[subject]', 'schema'));
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

        $ids = [];
        foreach ($this->arrayAt($response->json(), 'data') as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function tally(string $query): array
    {
        $response = $this->request('/api/inbItems/-actions/tally' . $query);
        $response->assertOk();

        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', \array_values($this->arrayAt($response->json(), 'meta', 'ids')));
        \sort($ids);

        return $ids;
    }

    private function assertValueInvalid(string $query, string $parameter): void
    {
        $response = $this->request('/api/inbItems?' . $query);

        $response->assertStatus(400);
        self::assertSame('FILTER_VALUE_INVALID', $response->json('errors.0.code'), $query);
        self::assertSame($parameter, $response->json('errors.0.source.parameter'), $query);
    }
}
