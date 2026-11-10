<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A positioned replace keeps the members a scope hides from the caller: they stay linked and
 * take the positions after the linkage's. Asserted on a declared identified `MorphToMany` on
 * the in-memory witness ({@see InMemoryHiddenPositionConformanceTest}) and the reference
 * Eloquent provider ({@see EloquentHiddenPositionConformanceTest}), each hiding song 2 its own
 * way. Queue 1 mixes songs 1, 2 and 3 at positions 0, 1 and 2.
 */
#[Group('spec:crud')]
abstract class HiddenPositionConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * Queue 1's mix as stored, hidden members included: each song id's position.
     *
     * @return array<int|string, int>
     */
    abstract protected function storedMix(): array;

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

    #[Test]
    public function aReplaceKeepsAHiddenMemberAfterTheLinkage(): void
    {
        $response = $this->json('PATCH', '/api/queues/1/relationships/mix', ['data' => [
            ['type' => 'songs', 'id' => '3'],
            ['type' => 'songs', 'id' => '1'],
        ]], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var list<array{id: string, meta: array{pivot: array{position: int}}}> $data */
        $data = $this->get('/api/queues/1/relationships/mix', ['Accept' => self::MEDIA_TYPE])->json('data');
        self::assertSame(['3' => 0, '1' => 1], \array_combine(\array_column($data, 'id'), \array_map(static fn(array $member): int => $member['meta']['pivot']['position'], $data)));

        self::assertSame([3 => 0, 1 => 1, 2 => 2], $this->storedMix());
    }
}
