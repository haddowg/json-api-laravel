<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A positioned to-many keeps its members in the order the linkage sets: a replace writes each
 * member's index, an add appends after the highest position, a remove leaves gaps, and the
 * relation reads in position order. Each case runs over a `hasMany` positioned by the related
 * row, a `belongsToMany` positioned by its join and an identified `MorphToMany` positioned by
 * its link rows, and identically on the in-memory witness
 * ({@see InMemoryPositionedConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentPositionedConformanceTest}), whose every position is unique per queue, so a
 * reorder that wrote positions in place would fail there.
 *
 * Queue 1 holds songs 1, 2 and 3 at positions 0, 1 and 2 on each relation. The in-memory
 * witness holds no join rows, so it declares no positioned join, which warm-up would refuse.
 */
#[Group('spec:crud')]
abstract class PositionedConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function relations(): iterable
    {
        yield 'hasMany' => ['songs'];
        yield 'belongsToMany' => ['favourites'];
        yield 'declared MorphToMany' => ['mix'];
        yield 'closure MorphToMany' => ['looseMix'];
    }

    #[Test]
    #[DataProvider('relations')]
    public function aReplaceWritesEachMembersLinkageIndex(string $relation): void
    {
        $this->write('PATCH', '/api/queues/1/relationships/' . $relation, $this->linkage(['3', '1', '2']), 200);

        self::assertSame(['3', '1', '2'], $this->ids($relation));
        $this->assertPositions($relation, ['3' => 0, '1' => 1, '2' => 2]);
    }

    #[Test]
    #[DataProvider('relations')]
    public function anAddAppendsAfterTheHighestPosition(string $relation): void
    {
        $this->write('POST', '/api/queues/1/relationships/' . $relation, $this->linkage(['4', '2']), 200);

        self::assertSame(['1', '2', '3', '4'], $this->ids($relation));
        $this->assertPositions($relation, ['1' => 0, '2' => 1, '3' => 2, '4' => 3]);
    }

    #[Test]
    #[DataProvider('relations')]
    public function aRemoveLeavesGapsAndALaterAddStillAppends(string $relation): void
    {
        $this->write('DELETE', '/api/queues/1/relationships/' . $relation, $this->linkage(['2']), 200);
        self::assertSame(['1', '3'], $this->ids($relation));
        $this->assertPositions($relation, ['1' => 0, '3' => 2]);

        $this->write('POST', '/api/queues/1/relationships/' . $relation, $this->linkage(['4']), 200);
        self::assertSame(['1', '3', '4'], $this->ids($relation));
        $this->assertPositions($relation, ['1' => 0, '3' => 2, '4' => 3]);
    }

    #[Test]
    #[DataProvider('relations')]
    public function aWholeResourcePatchReplacesThePositionsToo(string $relation): void
    {
        $this->write('PATCH', '/api/queues/1', ['data' => ['type' => 'queues', 'id' => '1', 'relationships' => [$relation => $this->linkage(['4', '3', '2'])]]], 200);

        self::assertSame(['4', '3', '2'], $this->ids($relation));
        $this->assertPositions($relation, ['4' => 0, '3' => 1, '2' => 2]);
    }

    #[Test]
    #[DataProvider('relations')]
    public function includedLinkageReadsInPositionOrder(string $relation): void
    {
        $this->write('PATCH', '/api/queues/1/relationships/' . $relation, $this->linkage(['2', '3', '1']), 200);

        /** @var list<array{id: string}> $linkage */
        $linkage = $this->read('/api/queues/1?include=' . $relation)->json('data.relationships.' . $relation . '.data');
        self::assertSame(['2', '3', '1'], \array_column($linkage, 'id'));
    }

    #[Test]
    public function aClientCannotSetAPositionItself(): void
    {
        $this->write('PATCH', '/api/queues/1/relationships/mix', ['data' => [
            ['type' => 'songs', 'id' => '2', 'meta' => ['pivot' => ['position' => 7]]],
            ['type' => 'songs', 'id' => '1'],
        ]], 200);

        $this->assertPositions('mix', ['2' => 0, '1' => 1]);
    }

    /**
     * @param array<int|string, int> $expected
     */
    private function assertPositions(string $relation, array $expected): void
    {
        if ($relation === 'songs') {
            /** @var list<array{id: string, attributes: array{slot: int|null}}> $songs */
            $songs = $this->read('/api/queues/1/songs')->json('data');
            self::assertSame($expected, \array_combine(\array_column($songs, 'id'), \array_map(static fn(array $song): ?int => $song['attributes']['slot'], $songs)));

            return;
        }

        /** @var list<array{id: string, meta: array{pivot: array{position: int|null}}}> $data */
        $data = $this->read('/api/queues/1/relationships/' . $relation)->json('data');
        self::assertSame($expected, \array_combine(\array_column($data, 'id'), \array_map(static fn(array $member): ?int => $member['meta']['pivot']['position'], $data)));
    }

    /**
     * @return list<string>
     */
    private function ids(string $relation): array
    {
        /** @var list<array{id: string}> $data */
        $data = $this->read('/api/queues/1/relationships/' . $relation)->json('data');

        return \array_column($data, 'id');
    }

    /**
     * @param list<string> $ids
     *
     * @return array{data: list<array{type: string, id: string}>}
     */
    private function linkage(array $ids): array
    {
        return ['data' => \array_map(static fn(string $id): array => ['type' => 'songs', 'id' => $id], $ids)];
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function write(string $method, string $uri, array $document, int $status): void
    {
        $response = $this->json($method, $uri, $document, ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    }
}
