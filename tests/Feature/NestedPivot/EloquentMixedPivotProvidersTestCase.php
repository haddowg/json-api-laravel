<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Crates and records on Eloquent with one of the two types served by a provider that reads
 * pivot rows only through the batched pivot SPI. Wherever crate 1's `records` pivot renders,
 * on its related and relationship endpoints and on a document's linkage with or without an
 * include, it renders the same values, read from the related type's provider.
 *
 * @internal
 */
abstract class EloquentMixedPivotProvidersTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The type served by the provider that reads pivot rows only through the batched SPI.
     */
    abstract protected function batchOnlyType(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('nested_pivot.batch_only', $this->batchOnlyType());

        return [JsonApiServiceProvider::class, NestedPivotServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        NestedPivotServiceProvider::migrate();
    }

    public function test_every_path_renders_the_same_pivot(): void
    {
        $expected = ['1' => $this->pivot(1), '2' => $this->pivot(2)];

        self::assertSame($expected, $this->pivots($this->document('/api/npCrates/1/records')['data'] ?? null), 'related endpoint');
        self::assertSame($expected, $this->pivots($this->document('/api/npCrates/1/relationships/records')['data'] ?? null), 'relationship endpoint');

        foreach (['/api/npCrates/1?include=records', '/api/npCrates/1'] as $uri) {
            self::assertSame($expected, $this->pivots(\data_get($this->document($uri), 'data.relationships.records.data')), $uri);
        }
        $nested = $this->document('/api/npShelves/1?include=crate');
        foreach ($this->arrayAt($nested['included'] ?? null) as $resource) {
            if (\data_get($resource, 'type') === 'npCrates' && \data_get($resource, 'id') === '1') {
                self::assertSame($expected, $this->pivots(\data_get($resource, 'relationships.records.data')), 'nested');
            }
        }
        $included = [];
        foreach ($this->arrayAt($this->document('/api/npCrates/1?include=records')['included'] ?? null) as $resource) {
            $id = \data_get($resource, 'id');
            self::assertIsString($id);
            $included[$id] = \data_get($resource, 'meta.pivot');
        }
        \ksort($included);
        self::assertSame($expected, $included, 'included');
    }

    /**
     * The `meta.pivot` crate 1's `records` renders for record `$record`.
     *
     * @return array<string, mixed>
     */
    private function pivot(int $record): array
    {
        $label = ['label' => 'Alpha / ' . NestedPivotServiceProvider::RECORDS[$record]];
        foreach (NestedPivotServiceProvider::LINKS as [$crate, $linked, $position, $addedAt]) {
            if ($crate === 1 && $linked === $record) {
                return ['position' => $position, 'addedAt' => \str_replace(' ', 'T', $addedAt) . '+00:00', ...$label];
            }
        }

        self::fail(\sprintf('Crate 1 holds no record %d.', $record));
    }

    /**
     * Each member's `meta.pivot`, keyed by id in id order.
     *
     * @return array<string, mixed>
     */
    private function pivots(mixed $members): array
    {
        $pivots = [];
        foreach ($this->arrayAt($members) as $member) {
            $id = \data_get($member, 'id');
            self::assertIsString($id);
            $pivots[$id] = \data_get($member, 'meta.pivot');
        }
        \ksort($pivots);

        return $pivots;
    }

    /**
     * @return array<mixed>
     */
    private function document(string $uri): array
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return $this->arrayAt($response->json());
    }

    /**
     * @return array<mixed>
     */
    private function arrayAt(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
