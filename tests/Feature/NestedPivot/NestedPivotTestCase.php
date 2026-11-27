<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A `belongsToMany` with pivot fields renders `meta.pivot` on its linkage and on the related
 * resources it includes wherever the include reaches it: from the primary type, through a
 * to-one or a to-many at any depth, on a collection or a single read, and from a related
 * endpoint. See {@see NestedPivotServiceProvider} for the rows.
 *
 * @internal
 */
abstract class NestedPivotTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Whether the provider reads stored pivot columns. One that doesn't renders only the
     * computed fields.
     */
    abstract protected function storesPivots(): bool;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, NestedPivotServiceProvider::class];
    }

    public function test_a_primary_include_renders_pivot_on_the_linkage_and_the_included_resources(): void
    {
        $document = $this->document('/api/npCrates/1?include=records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame($this->pivot(1, 1), $this->includedPivot($document, 'npRecords', '1'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_a_primary_collection_include_renders_each_parents_own_pivot(): void
    {
        $document = $this->document('/api/npCrates?include=records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->primary($document, '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->primary($document, '2'), 'records'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_a_nested_include_on_a_collection_renders_pivot(): void
    {
        $document = $this->document('/api/npShelves?include=crate.records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($document, 'npCrates', '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($document, 'npCrates', '2'), 'records'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_a_nested_include_on_a_single_read_renders_pivot(): void
    {
        $document = $this->document('/api/npShelves/2?include=crate.records');

        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($document, 'npCrates', '2'), 'records'));
        self::assertSame($this->pivot(2, 1), $this->includedPivot($document, 'npRecords', '1'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_a_nested_include_of_an_all_computed_pivot_relation_renders_pivot(): void
    {
        $document = $this->document('/api/npShelves/2?include=crate.labelledRecords');

        self::assertSame(
            ['1' => ['label' => 'Beta / One'], '3' => ['label' => 'Beta / Three']],
            $this->linkagePivots($this->included($document, 'npCrates', '2'), 'labelledRecords'),
        );
        self::assertSame(['label' => 'Beta / Three'], $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_an_include_three_levels_deep_renders_pivot(): void
    {
        foreach (['/api/npRooms/1?include=shelves.crate.records', '/api/npRooms?include=shelves.crate.records'] as $uri) {
            $document = $this->document($uri);

            self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($document, 'npCrates', '1'), 'records'), $uri);
            self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($document, 'npCrates', '2'), 'records'), $uri);
            self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'), $uri);
        }
    }

    public function test_a_resource_reached_by_two_paths_carries_each_parents_pivot_on_its_linkage(): void
    {
        $document = $this->document('/api/npShelves/1?include=crate.records,altCrate.records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($document, 'npCrates', '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($document, 'npCrates', '2'), 'records'));

        $records = [];
        foreach ($this->arrayAt($document['included'] ?? null) as $resource) {
            if (\data_get($resource, 'type') === 'npRecords') {
                $records[] = \data_get($resource, 'id');
            }
        }
        \sort($records);
        self::assertSame(['1', '2', '3'], $records);
        // Record 1 is included once, so it carries the pivot of one of the two crates holding it.
        self::assertContains($this->includedPivot($document, 'npRecords', '1'), [$this->pivot(1, 1), $this->pivot(2, 1)]);
    }

    public function test_sparse_fieldsets_keep_the_pivot_on_a_nested_path(): void
    {
        $document = $this->document('/api/npShelves?include=crate.records&fields[npShelves]=crate&fields[npCrates]=records&fields[npRecords]=title');

        $crate = $this->included($document, 'npCrates', '1');
        self::assertArrayNotHasKey('attributes', $crate);
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($crate, 'records'));

        $record = $this->included($document, 'npRecords', '2');
        self::assertSame(['title' => 'Two'], $record['attributes'] ?? null);
        self::assertArrayNotHasKey('relationships', $record);
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_an_include_from_a_related_endpoint_renders_pivot(): void
    {
        $toOne = $this->document('/api/npShelves/1/crate?include=records');
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->arrayAt($toOne['data'] ?? null), 'records'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($toOne, 'npRecords', '2'));

        $toMany = $this->document('/api/npRecords/1/crates?include=records');
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->primary($toMany, '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->primary($toMany, '2'), 'records'));
    }

    public function test_the_related_and_relationship_endpoints_render_pivot(): void
    {
        $related = $this->document('/api/npCrates/2/records');
        self::assertSame($this->pivot(2, 1), \data_get($this->primary($related, '1'), 'meta.pivot'));
        self::assertSame($this->pivot(2, 3), \data_get($this->primary($related, '3'), 'meta.pivot'));

        $relationship = $this->document('/api/npCrates/2/relationships/records');
        self::assertSame($this->pivot(2, 1), \data_get($this->primary($relationship, '1'), 'meta.pivot'));
        self::assertSame($this->pivot(2, 3), \data_get($this->primary($relationship, '3'), 'meta.pivot'));
    }

    /**
     * The `meta.pivot` crate `$crate`'s `records` relation renders for record `$record`.
     *
     * @return array<string, mixed>
     */
    protected function pivot(int $crate, int $record): array
    {
        $label = NestedPivotServiceProvider::CRATES[$crate] . ' / ' . NestedPivotServiceProvider::RECORDS[$record];
        if (!$this->storesPivots()) {
            return ['label' => $label];
        }

        foreach (NestedPivotServiceProvider::LINKS as [$linkCrate, $linkRecord, $position, $addedAt]) {
            if ($linkCrate === $crate && $linkRecord === $record) {
                return ['position' => $position, 'addedAt' => \str_replace(' ', 'T', $addedAt) . '+00:00', 'label' => $label];
            }
        }

        self::fail(\sprintf('Crate %d holds no record %d.', $crate, $record));
    }

    /**
     * @return array<mixed>
     */
    protected function document(string $uri): array
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
     * Each linkage member's `meta.pivot`, keyed by id, `null` for a member with none.
     *
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

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    private function primary(array $document, string $id): array
    {
        foreach ($this->arrayAt($document['data'] ?? null) as $resource) {
            if (\data_get($resource, 'id') === $id) {
                return $this->arrayAt($resource);
            }
        }

        self::fail('No primary resource ' . $id . '.');
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    private function included(array $document, string $type, string $id): array
    {
        foreach ($this->arrayAt($document['included'] ?? []) as $resource) {
            if (\data_get($resource, 'type') === $type && \data_get($resource, 'id') === $id) {
                return $this->arrayAt($resource);
            }
        }

        self::fail(\sprintf('No included %s %s.', $type, $id));
    }

    /**
     * @param array<mixed> $document
     */
    private function includedPivot(array $document, string $type, string $id): mixed
    {
        return \data_get($this->included($document, $type, $id), 'meta.pivot');
    }
}
