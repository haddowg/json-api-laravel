<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many reads its members in its own default order, else the related type's, on every
 * path a client reaches them by: the related and relationship endpoints, included and
 * eager linkage with batching on or off, and a Relationship Queries window. Asserted
 * identically on the in-memory witness ({@see InMemoryRelationOrderConformanceTest}) and
 * the reference Eloquent provider ({@see EloquentRelationOrderConformanceTest}).
 *
 * Shelf 1 holds books 1 to 4. Its `books` read newest first by their own default,
 * `favourites` by the books' title default, and a requested sort replaces either.
 */
#[Group('spec:fetching')]
abstract class RelationOrderConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * Shelf 1's `picks` with no sort requested, or `null` where the provider can't order by
     * the pivot rank and the relation isn't declared.
     *
     * @return list<string>|null
     */
    abstract protected function expectedPicks(): ?array;

    /**
     * Shelf 1's `classics` with no sort requested.
     *
     * @return list<string>
     */
    abstract protected function expectedClassics(): array;

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

    /**
     * @return iterable<string, array{bool}>
     */
    public static function batching(): iterable
    {
        yield 'batched' => [true];
        yield 'unbatched' => [false];
    }

    #[Test]
    public function theRelatedEndpointReadsInTheRelationsOwnOrder(): void
    {
        self::assertSame(['2', '4', '3', '1'], $this->dataIds('/api/shelves/1/books'));
    }

    #[Test]
    public function theRelationshipEndpointReadsInTheRelationsOwnOrder(): void
    {
        self::assertSame(['2', '4', '3', '1'], $this->dataIds('/api/shelves/1/relationships/books'));
    }

    #[Test]
    #[DataProvider('batching')]
    public function includedLinkageReadsInTheRelationsOwnOrder(bool $batched): void
    {
        $this->batch($batched);

        self::assertSame(['2', '4', '3', '1'], $this->linkageIds($this->document('/api/shelves/1?include=books'), 'books'));

        $collection = $this->document('/api/shelves?include=books');
        self::assertSame(['2', '4', '3', '1'], $this->linkageIds($collection, 'books', 0));
        self::assertSame(['5'], $this->linkageIds($collection, 'books', 1));
    }

    #[Test]
    public function aRequestedSortReplacesTheRelationsOwnOrder(): void
    {
        self::assertSame(['2', '4', '1', '3'], $this->dataIds('/api/shelves/1/books?sort=title'));
        self::assertSame(['1', '3', '4', '2'], $this->dataIds('/api/shelves/1/relationships/books?sort=year'));
    }

    #[Test]
    #[DataProvider('batching')]
    public function aRelationWithoutAnOrderReadsInTheRelatedTypesOrder(bool $batched): void
    {
        $this->batch($batched);

        self::assertSame(['4', '1', '3'], $this->dataIds('/api/shelves/1/favourites'));
        self::assertSame(['4', '1', '3'], $this->dataIds('/api/shelves/1/relationships/favourites'));

        // `favourites` renders its linkage without an include.
        self::assertSame(['4', '1', '3'], $this->linkageIds($this->document('/api/shelves/1'), 'favourites'));
        self::assertSame(['4', '1', '3'], $this->linkageIds($this->document('/api/shelves?include=favourites'), 'favourites', 0));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelationshipQueriesWindowKeepsTheRelationsOwnOrder(): void
    {
        $response = $this->get(
            '/api/shelves/1?include=books&relatedQuery[books][filter][genre]=sf',
            ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"'],
        );
        $response->assertOk();

        /** @var array<string, mixed> $document */
        $document = $response->json();
        self::assertSame(['2', '4', '1'], $this->linkageIds($document, 'books'));
    }

    #[Test]
    #[DataProvider('batching')]
    public function providerSpecificOrdersAgreeOnEveryPath(bool $batched): void
    {
        $this->batch($batched);

        foreach (\array_filter(['picks' => $this->expectedPicks(), 'classics' => $this->expectedClassics()]) as $relation => $expected) {
            self::assertSame($expected, $this->dataIds('/api/shelves/1/' . $relation), $relation);
            self::assertSame($expected, $this->dataIds('/api/shelves/1/relationships/' . $relation), $relation);
            self::assertSame($expected, $this->linkageIds($this->document('/api/shelves/1?include=' . $relation), $relation), $relation);
        }
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentDeclaresOnlyTheRelationsOwnOrder(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);
        $document = $factory->forServer('default')->toArray();

        self::assertSame(['-year'], $this->sortDefault($document, '/shelves/{id}/books'));
        self::assertSame(['-year'], $this->sortDefault($document, '/shelves/{id}/relationships/books'));
        if ($this->expectedPicks() !== null) {
            self::assertSame(['rank'], $this->sortDefault($document, '/shelves/{id}/picks'));
        }
        self::assertNull($this->sortDefault($document, '/shelves/{id}/favourites'));
    }

    /**
     * @param array<mixed> $document
     */
    private function sortDefault(array $document, string $path): mixed
    {
        /** @var list<array{name?: string, schema?: array<string, mixed>}> $parameters */
        $parameters = data_get($document, ['paths', $path, 'get', 'parameters'], []);
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) === 'sort') {
                return $parameter['schema']['default'] ?? null;
            }
        }

        self::fail(\sprintf('%s has no sort parameter.', $path));
    }

    private function batch(bool $batched): void
    {
        $batcher = $this->app?->make(RelatedIncludeBatcher::class);
        \assert($batcher instanceof RelatedIncludeBatcher);
        $batched ? $batcher->enable() : $batcher->disable();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $uri): array
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        /** @var array<string, mixed> $document */
        $document = $response->json();

        return $document;
    }

    /**
     * @return list<string>
     */
    private function dataIds(string $uri): array
    {
        /** @var list<array{id: string}> $data */
        $data = $this->document($uri)['data'];

        return \array_column($data, 'id');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function linkageIds(array $document, string $relation, ?int $index = null): array
    {
        /** @var list<array{id: string}> $linkage */
        $linkage = data_get($document, $index === null ? ['data', 'relationships', $relation, 'data'] : ['data', $index, 'relationships', $relation, 'data']);

        return \array_column($linkage, 'id');
    }
}
