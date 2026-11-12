<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * An identified MorphToMany's link rows carry pivot fields: each member renders them as
 * `meta.pivot`, its endpoints sort, filter and page over them, it reads in its default order
 * by them, and a write lands them on the rows. Asserted identically on the in-memory witness
 * ({@see InMemoryLinkRowsConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentLinkRowsConformanceTest}), where `items` reads a row model and `pins` a
 * morph pivot.
 *
 * Board 1 links note 1 at position 2, photo 1 at 0, note 2 at 3 and photo 2 at 1, the photos
 * labelled `trip`.
 */
#[Group('spec:fetching')]
abstract class LinkRowsConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array DEFAULT_ORDER = ['photos:1', 'photos:2', 'notes:1', 'notes:2'];

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

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function theRelationshipEndpointRendersEachRowsPivotInTheDefaultOrder(): void
    {
        $data = $this->data('/api/boards/1/relationships/items');

        self::assertSame(self::DEFAULT_ORDER, $this->members($data));
        self::assertSame(['position' => 0, 'label' => 'trip'], $data[0]['meta']['pivot'] ?? null);
        self::assertSame(['position' => 3, 'label' => 'work'], $data[3]['meta']['pivot'] ?? null);
    }

    #[Test]
    public function theRelatedEndpointRendersEachRowsPivotOnItsResource(): void
    {
        $data = $this->data('/api/boards/1/items');

        self::assertSame(self::DEFAULT_ORDER, $this->members($data));
        self::assertSame('Beach', $data[0]['attributes']['caption'] ?? null);
        self::assertSame(['position' => 2, 'label' => 'home'], $data[2]['meta']['pivot'] ?? null);
    }

    #[Test]
    public function includedLinkageReadsInTheDefaultOrderWithItsPivot(): void
    {
        $response = $this->read('/api/boards/1?include=items');

        /** @var list<array{type: string, id: string, meta?: array{pivot?: mixed}}> $linkage */
        $linkage = $response->json('data.relationships.items.data');
        self::assertSame(self::DEFAULT_ORDER, $this->members($linkage));
        self::assertSame(['position' => 1, 'label' => 'trip'], $linkage[1]['meta']['pivot'] ?? null);
    }

    #[Test]
    public function bothEndpointsSortByAPivotField(): void
    {
        self::assertSame(['notes:2', 'notes:1', 'photos:2', 'photos:1'], $this->members($this->data('/api/boards/1/items?sort=-position')));
        self::assertSame(['notes:2', 'notes:1', 'photos:2', 'photos:1'], $this->members($this->data('/api/boards/1/relationships/items?sort=-position')));

        // Tied labels break by stored type, then id.
        self::assertSame(['notes:1', 'photos:1', 'photos:2', 'notes:2'], $this->members($this->data('/api/boards/1/relationships/items?sort=label')));
    }

    #[Test]
    public function bothEndpointsFilterByAPivotField(): void
    {
        self::assertSame(['photos:1', 'photos:2'], $this->members($this->data('/api/boards/1/items?filter[label]=trip')));
        self::assertSame(['photos:1', 'photos:2'], $this->members($this->data('/api/boards/1/relationships/items?filter[label]=trip')));
    }

    #[Test]
    public function bothEndpointsPageTheSortedRows(): void
    {
        self::assertSame(['notes:1', 'notes:2'], $this->members($this->data('/api/boards/1/items?page[number]=2&page[size]=2')));

        self::assertSame(['photos:2'], $this->members($this->data('/api/boards/1/relationships/items?sort=-position&filter[label]=trip&page[size]=1')));
    }

    #[Test]
    public function aKeyOutsideThePivotVocabularyIsRefused(): void
    {
        $this->read('/api/boards/1/items?sort=title', 400);
        $this->read('/api/boards/1/relationships/items?filter[title]=Ideas', 400);
    }

    #[Test]
    public function aMorphPivotReadsInItsDefaultOrderAndSorts(): void
    {
        $data = $this->data('/api/boards/1/relationships/pins');
        self::assertSame(['notes:1', 'notes:3'], $this->members($data));
        self::assertSame(['rank' => 0], $data[0]['meta']['pivot'] ?? null);

        self::assertSame(['notes:3', 'notes:1'], $this->members($this->data('/api/boards/1/pins?sort=-rank')));
    }

    #[Test]
    public function theRelatedPageCountsARelationOnTheMemberTypeThatDeclaresIt(): void
    {
        $counts = ['Accept' => self::MEDIA_TYPE . ';profile="https://haddowg.github.io/json-api/profiles/countable/"'];

        // Board 1 pins note 1 and not note 2; a photo has no `pinnedOn`.
        $response = $this->get('/api/boards/1/items?withCount=pinnedOn', $counts);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        $totals = \array_map(static fn(array $member): mixed => data_get($member, 'relationships.pinnedOn.meta.total'), $data);
        self::assertSame(['photos:1' => null, 'photos:2' => null, 'notes:1' => 1, 'notes:2' => 0], \array_combine($this->members($data), $totals));

        $refused = $this->get('/api/boards/1/items?withCount=title', $counts);
        self::assertSame(400, $refused->getStatusCode(), (string) $refused->getContent());
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $refused->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aReplaceWritesEachMembersPivotOntoItsRow(): void
    {
        $response = $this->write('PATCH', '/api/boards/1/relationships/items', ['data' => [
            ['type' => 'notes', 'id' => '2', 'meta' => ['pivot' => ['position' => 0, 'label' => 'first']]],
            ['type' => 'notes', 'id' => '3', 'meta' => ['pivot' => ['position' => 1, 'label' => 'new']]],
            ['type' => 'photos', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->data('/api/boards/1/relationships/items');
        // Tied positions break by stored type, then id.
        self::assertSame(['notes:2', 'photos:1', 'notes:3'], $this->members($data));
        self::assertSame(['position' => 0, 'label' => 'first'], $data[0]['meta']['pivot'] ?? null);
        self::assertSame(['position' => 0, 'label' => 'trip'], $data[1]['meta']['pivot'] ?? null);
        self::assertSame(['position' => 1, 'label' => 'new'], $data[2]['meta']['pivot'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anAddWritesTheNewMembersPivotThroughAWholeResourcePatchToo(): void
    {
        $response = $this->write('POST', '/api/boards/2/relationships/items', ['data' => [
            ['type' => 'photos', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'label' => 'view']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $response = $this->write('PATCH', '/api/boards/2', ['data' => ['type' => 'boards', 'id' => '2', 'relationships' => ['items' => ['data' => [
            ['type' => 'photos', 'id' => '2', 'meta' => ['pivot' => ['label' => 'renamed']]],
            ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['position' => 1]]],
        ]]]]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->data('/api/boards/2/relationships/items');
        self::assertSame(['notes:1', 'photos:2'], $this->members($data));
        self::assertSame(['position' => 1, 'label' => null], $data[0]['meta']['pivot'] ?? null);
        self::assertSame(['position' => 5, 'label' => 'renamed'], $data[1]['meta']['pivot'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aClosureRelationsPivotLandsOnTheRowsItsHookWrites(): void
    {
        $response = $this->write('PATCH', '/api/boards/2/relationships/looseItems', ['data' => [
            ['type' => 'notes', 'id' => '3', 'meta' => ['pivot' => ['label' => 'loose']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->data('/api/boards/2/relationships/looseItems');
        self::assertSame(['notes:3'], $this->members($data));
        self::assertSame(['label' => 'loose'], $data[0]['meta']['pivot'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMistypedPivotValueIsRefused(): void
    {
        $response = $this->write('PATCH', '/api/boards/1/relationships/items', ['data' => [
            ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['position' => 'first']]],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/0/meta/pivot/position', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentOffersThePivotSortsAndFiltersOnBothEndpoints(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);
        $document = $factory->forServer('default')->toArray();

        foreach (['/boards/{id}/items', '/boards/{id}/relationships/items'] as $path) {
            /** @var list<array{name?: string}> $parameters */
            $parameters = data_get($document, ['paths', $path, 'get', 'parameters'], []);
            $names = \array_column($parameters, 'name');
            self::assertContains('sort', $names, $path);
            self::assertContains('filter[label]', $names, $path);
        }
    }

    #[Test]
    public function warmUpNotesOnlyTheClosureRelationIsServedInMemory(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $notices = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->notices[] = (string) $message;
            }
        };
        $this->app?->instance(LoggerInterface::class, $logger);
        $warmer = $this->app?->make(ServableResourceWarmer::class);
        \assert($warmer instanceof ServableResourceWarmer);
        $warmer->warm();

        self::assertCount(2, $logger->notices);
        self::assertStringContainsString('MorphToMany relation "looseItems" reads its link rows through an identifiedBy() closure', $logger->notices[0]);
        self::assertStringContainsString('MorphToMany relation "pins" reads its link rows through an identifiedBy() closure', $logger->notices[1]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, int $status = 200): TestResponse
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @return list<array{type: string, id: string, attributes?: array<string, mixed>, meta?: array{pivot?: mixed}}>
     */
    private function data(string $uri): array
    {
        /** @var list<array{type: string, id: string, attributes?: array<string, mixed>, meta?: array{pivot?: mixed}}> $data */
        $data = $this->read($uri)->json('data');

        return $data;
    }

    /**
     * @param list<array{type: string, id: string}> $data
     *
     * @return list<string>
     */
    private function members(array $data): array
    {
        return \array_map(static fn(array $member): string => $member['type'] . ':' . $member['id'], $data);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }
}
