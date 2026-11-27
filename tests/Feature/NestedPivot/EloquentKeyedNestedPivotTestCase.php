<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpCrate;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpCrateResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpKeys;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpRecord;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Pivots rendered through includes on Eloquent with the crates and records keyed some other
 * way than plain integers, so every map the render keys by parent and member id, and every
 * read that matches a pivot row to a parent, meets the key in each of its forms.
 *
 * @internal
 */
abstract class EloquentKeyedNestedPivotTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    abstract protected function keys(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('nested_pivot.keys', $this->keys());

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

    public function test_a_nested_include_renders_each_crates_pivot_under_its_wire_ids(): void
    {
        $document = $this->document('/api/npShelves?include=crate.records');

        self::assertSame($this->pivots(1), $this->linkagePivots($this->resource($document['included'] ?? null, 'npCrates', NpKeys::wire('c', 1)), 'records'));
        self::assertSame($this->pivots(2), $this->linkagePivots($this->resource($document['included'] ?? null, 'npCrates', NpKeys::wire('c', 2)), 'records'));
        self::assertSame($this->pivot(2, 3), \data_get($this->resource($document['included'] ?? null, 'npRecords', NpKeys::wire('r', 3)), 'meta.pivot'));
    }

    public function test_a_primary_include_renders_the_pivot_under_its_wire_ids(): void
    {
        $document = $this->document('/api/npCrates/' . NpKeys::wire('c', 1) . '?include=records');

        self::assertSame(NpKeys::wire('c', 1), \data_get($document, 'data.id'));
        self::assertSame($this->pivots(1), $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame($this->pivot(1, 2), \data_get($this->resource($document['included'] ?? null, 'npRecords', NpKeys::wire('r', 2)), 'meta.pivot'));
    }

    public function test_the_related_endpoint_and_a_polymorphic_page_render_the_pivot(): void
    {
        $related = $this->document('/api/npCrates/' . NpKeys::wire('c', 2) . '/records');
        self::assertSame($this->pivot(2, 3), \data_get($this->resource($related['data'] ?? null, 'npRecords', NpKeys::wire('r', 3)), 'meta.pivot'));

        $page = $this->document('/api/npRooms/1/things?include=records');
        self::assertSame($this->pivots(2), $this->linkagePivots($this->resource($page['data'] ?? null, 'npCrates', NpKeys::wire('c', 2)), 'records'));
    }

    public function test_an_update_response_renders_the_pivot(): void
    {
        $response = $this->call('PATCH', '/api/npBins/' . NpKeys::wire('c', 1) . '?include=records', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => ['type' => 'npBins', 'id' => NpKeys::wire('c', 1), 'attributes' => ['title' => 'Alpha']]]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $records = [];
        foreach ([1 => 1, 2 => 2] as $record => $position) {
            $records[NpKeys::wire('r', $record)] = ['position' => $position, 'label' => 'Alpha / ' . NestedPivotServiceProvider::RECORDS[$record]];
        }
        self::assertSame($records, $this->linkagePivots($this->arrayAt(\data_get($response->json(), 'data')), 'records'));
    }

    public function test_a_morph_identified_by_a_callback_includes_its_target_under_its_wire_id(): void
    {
        $shelf = $this->document('/api/npShelves/2?include=tagged');
        self::assertSame(['type' => 'npCrates', 'id' => NpKeys::wire('c', 2)], \data_get($shelf, 'data.relationships.tagged.data'));
        self::assertSame('Beta', \data_get($this->resource($shelf['included'] ?? null, 'npCrates', NpKeys::wire('c', 2)), 'attributes.title'));

        $room = $this->document('/api/npRooms/1?include=pinned');
        self::assertSame([NpKeys::wire('c', 1), NpKeys::wire('c', 2)], \array_column($this->arrayAt(\data_get($room, 'data.relationships.pinned.data')), 'id'));
        self::assertSame('Alpha', \data_get($this->resource($room['included'] ?? null, 'npCrates', NpKeys::wire('c', 1)), 'attributes.title'));
    }

    public function test_a_crate_is_found_by_its_id_in_either_case(): void
    {
        foreach ([\strtolower(NpKeys::wire('c', 1)), \strtoupper(NpKeys::wire('c', 1))] as $id) {
            if (NpKeys::kind() === 'prefix' && $id !== NpKeys::wire('c', 1)) {
                continue;
            }
            self::assertSame(NpKeys::wire('c', 1), \data_get($this->document('/api/npCrates/' . $id), 'data.id'), $id);
        }
    }

    public function test_the_batched_pivot_read_keys_each_parent_and_member_by_its_wire_id(): void
    {
        $relation = null;
        foreach ((new NpCrateResource())->allFields() as $field) {
            if ($field instanceof BelongsToMany && $field->name() === 'records') {
                $relation = $field;
            }
        }
        self::assertNotNull($relation);

        $provider = new EloquentDataProvider(['npCrates' => NpCrate::class, 'npRecords' => NpRecord::class]);
        $maps = $provider->fetchRelatedPivotMapBatch('npCrates', 'npRecords', \array_values(NpCrate::query()->get()->all()), $relation);

        self::assertEqualsCanonicalizing([NpKeys::wire('c', 1), NpKeys::wire('c', 2)], \array_map('strval', \array_keys($maps)));
        self::assertSame(5, $maps[NpKeys::wire('c', 2)][NpKeys::wire('r', 1)]['position'] ?? null);
        self::assertSame(6, $maps[NpKeys::wire('c', 2)][NpKeys::wire('r', 3)]['position'] ?? null);
    }

    /**
     * Crate `$crate`'s `records` pivots keyed by each record's wire id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pivots(int $crate): array
    {
        $pivots = [];
        foreach (NestedPivotServiceProvider::LINKS as [$linkCrate, $record]) {
            if ($linkCrate === $crate) {
                $pivots[NpKeys::wire('r', $record)] = $this->pivot($crate, $record);
            }
        }

        return $pivots;
    }

    /**
     * @return array<string, mixed>
     */
    private function pivot(int $crate, int $record): array
    {
        foreach (NestedPivotServiceProvider::LINKS as [$linkCrate, $linkRecord, $position, $addedAt]) {
            if ($linkCrate === $crate && $linkRecord === $record) {
                return [
                    'position' => $position,
                    'addedAt' => \str_replace(' ', 'T', $addedAt) . '+00:00',
                    'label' => NestedPivotServiceProvider::CRATES[$crate] . ' / ' . NestedPivotServiceProvider::RECORDS[$record],
                ];
            }
        }

        self::fail(\sprintf('Crate %d holds no record %d.', $crate, $record));
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

    /**
     * @return array<mixed>
     */
    private function resource(mixed $resources, string $type, string $id): array
    {
        foreach ($this->arrayAt($resources) as $resource) {
            if (\data_get($resource, 'type') === $type && \data_get($resource, 'id') === $id) {
                return $this->arrayAt($resource);
            }
        }

        self::fail(\sprintf('No %s %s.', $type, $id));
    }

    /**
     * @param array<mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function linkagePivots(array $resource, string $relationship): array
    {
        $pivots = [];
        foreach ($this->arrayAt(\data_get($resource, 'relationships.' . $relationship . '.data')) as $identifier) {
            $id = \data_get($identifier, 'id');
            self::assertIsString($id);
            $pivots[$id] = \data_get($identifier, 'meta.pivot');
        }

        return $pivots;
    }
}
