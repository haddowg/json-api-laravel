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

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    /**
     * Whether the provider reads stored pivot columns. One that doesn't renders only the
     * computed fields.
     */
    abstract protected function storesPivots(): bool;

    /**
     * Whether the provider stores the pivot values a write sends, and reads the pivot
     * columns of the records' crates.
     */
    protected function storesWrittenPivots(): bool
    {
        return $this->storesPivots();
    }

    /**
     * Whether the provider reads the stored pivot columns of a record's crates.
     */
    protected function storesRecordPivots(): bool
    {
        return $this->storesWrittenPivots();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);

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

    public function test_an_include_below_a_polymorphic_related_page_renders_pivot(): void
    {
        $toOne = $this->document('/api/npShelves/2/holder?include=records');
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->arrayAt($toOne['data'] ?? null), 'records'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($toOne, 'npRecords', '3'));

        $toMany = $this->document('/api/npRooms/1/things?include=records');
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->primary($toMany, '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->primary($toMany, '2'), 'records'));
    }

    public function test_an_include_through_a_polymorphic_relation_renders_pivot(): void
    {
        $toOne = $this->document('/api/npShelves/2?include=holder.records');
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($toOne, 'npCrates', '2'), 'records'));

        $toMany = $this->document('/api/npRooms/1?include=things.records');
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($toMany, 'npCrates', '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($toMany, 'npCrates', '2'), 'records'));
    }

    public function test_an_include_through_an_identified_polymorphic_relation_renders_pivot(): void
    {
        $toOne = $this->document('/api/npShelves/2?include=tagged.records');
        self::assertSame(['type' => 'npCrates', 'id' => '2'], \data_get($toOne, 'data.relationships.tagged.data'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($toOne, 'npCrates', '2'), 'records'));

        $toMany = $this->document('/api/npRooms/1?include=pinned.records');
        self::assertSame([['type' => 'npCrates', 'id' => '1'], ['type' => 'npCrates', 'id' => '2']], \data_get($toMany, 'data.relationships.pinned.data'));
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($toMany, 'npCrates', '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($toMany, 'npCrates', '2'), 'records'));
    }

    public function test_a_not_sparse_relation_carries_the_pivot_below_it(): void
    {
        $document = $this->document('/api/npShelves/1?include=pinnedCrate.records&fields[npShelves]=name');

        self::assertSame(['type' => 'npCrates', 'id' => '1'], \data_get($document, 'data.relationships.pinnedCrate.data'));
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($document, 'npCrates', '1'), 'records'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_an_update_response_renders_pivot_at_every_depth(): void
    {
        $document = $this->write('PATCH', '/api/npBins/1?include=records.placedIn', 200, ['data' => [
            'type' => 'npBins', 'id' => '1', 'attributes' => ['title' => 'Alpha'],
        ]]);

        self::assertSame(['1' => $this->binPivot('Alpha', 1, 1), '2' => $this->binPivot('Alpha', 2, 2)], $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame(['1' => $this->placedInPivot(1), '2' => $this->placedInPivot(5)], $this->linkagePivots($this->included($document, 'npRecords', '1'), 'placedIn'));
    }

    public function test_a_create_response_renders_pivot(): void
    {
        $document = $this->write('POST', '/api/npBins?include=records', 201, ['data' => [
            'type' => 'npBins',
            'attributes' => ['title' => 'Gamma'],
            'relationships' => ['records' => ['data' => [['type' => 'npRecords', 'id' => '2', 'meta' => ['pivot' => ['position' => 9]]]]]],
        ]]);

        self::assertSame(['2' => $this->binPivot('Gamma', 2, 9)], $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame($this->binPivot('Gamma', 2, 9), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_an_atomic_result_renders_the_pivot_of_a_relation_with_data(): void
    {
        $document = $this->write('POST', '/api/operations', 200, ['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'npBins', 'id' => '2'], 'data' => ['type' => 'npBins', 'id' => '2', 'attributes' => ['title' => 'Beta']]],
        ]], self::ATOMIC_MEDIA_TYPE);

        self::assertSame(
            ['1' => $this->binPivot('Beta', 1, 5), '3' => $this->binPivot('Beta', 3, 6)],
            $this->linkagePivots($this->arrayAt(\data_get($document, 'atomic:results.0.data')), 'records'),
        );
    }

    public function test_a_not_sparse_pivot_relation_stays_outside_the_fieldset(): void
    {
        foreach ([$this->document('/api/npBins/1?include=keptRecords&fields[npBins]=title'), $this->action('/api/npBins/1/-actions/muster?include=keptRecords&fields[npBins]=title')] as $document) {
            $data = $this->arrayAt($document['data'] ?? null);
            self::assertSame(['title'], \array_keys($this->arrayAt($data['attributes'] ?? null)));
            self::assertSame(['keptRecords'], \array_keys($this->arrayAt($data['relationships'] ?? null)));
            self::assertSame(['1' => $this->keptPivot(1), '2' => $this->keptPivot(2)], $this->linkagePivots($data, 'keptRecords'));
            self::assertSame($this->keptPivot(2), $this->includedPivot($document, 'npRecords', '2'));
        }
    }

    public function test_an_action_response_renders_the_pivot_of_its_include(): void
    {
        $document = $this->action('/api/npCrates/1/-actions/muster?include=records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame($this->pivot(1, 1), $this->includedPivot($document, 'npRecords', '1'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_an_action_response_renders_the_pivot_of_a_nested_include(): void
    {
        $document = $this->action('/api/npShelves/2/-actions/muster?include=crate.records');

        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->included($document, 'npCrates', '2'), 'records'));
        self::assertSame($this->pivot(2, 1), $this->includedPivot($document, 'npRecords', '1'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_an_action_response_renders_the_pivot_of_a_relation_with_data(): void
    {
        $document = $this->action('/api/npBins/2/-actions/muster');

        self::assertSame(
            ['1' => $this->binPivot('Beta', 1, 5), '3' => $this->binPivot('Beta', 3, 6)],
            $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'),
        );
        self::assertArrayNotHasKey('included', $document);
    }

    public function test_a_collection_action_response_renders_each_parents_pivot(): void
    {
        $document = $this->action('/api/npCrates/-actions/roll?include=records');

        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->primary($document, '1'), 'records'));
        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->primary($document, '2'), 'records'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_a_mixed_type_action_response_renders_each_types_pivot(): void
    {
        $document = $this->action('/api/npCrates/-actions/mix?include=records');

        $crate = $this->primaryOf($document, 'npCrates', '1');
        $bin = $this->primaryOf($document, 'npBins', '2');
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($crate, 'records'));
        self::assertSame(['1' => $this->binPivot('Beta', 1, 5), '3' => $this->binPivot('Beta', 3, 6)], $this->linkagePivots($bin, 'records'));
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));
    }

    public function test_an_accepted_actions_job_resource_renders_its_pivot(): void
    {
        $document = $this->action('/api/npCrates/2/-actions/queue?include=records', 202);

        self::assertSame(['1' => $this->pivot(2, 1), '3' => $this->pivot(2, 3)], $this->linkagePivots($this->arrayAt($document['data'] ?? null), 'records'));
        self::assertSame($this->pivot(2, 3), $this->includedPivot($document, 'npRecords', '3'));
    }

    public function test_a_sparse_fieldset_on_an_action_response_keeps_the_pivot(): void
    {
        $document = $this->action('/api/npShelves/1/-actions/muster?include=crate.records&fields[npShelves]=crate&fields[npCrates]=records&fields[npRecords]=title');

        $crate = $this->included($document, 'npCrates', '1');
        self::assertArrayNotHasKey('attributes', $crate);
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($crate, 'records'));

        $record = $this->included($document, 'npRecords', '2');
        self::assertSame(['title' => 'Two'], $record['attributes'] ?? null);
        self::assertSame($this->pivot(1, 2), $this->includedPivot($document, 'npRecords', '2'));

        // A shelf's `pinnedCrate` is declared notSparseField().
        $document = $this->action('/api/npShelves/1/-actions/muster?include=pinnedCrate.records&fields[npShelves]=name');
        self::assertSame(['pinnedCrate'], \array_keys($this->arrayAt(\data_get($document, 'data.relationships'))));
        self::assertSame(['1' => $this->pivot(1, 1), '2' => $this->pivot(1, 2)], $this->linkagePivots($this->included($document, 'npCrates', '1'), 'records'));
    }

    public function test_every_rendered_pivot_linkage_carries_its_pivot(): void
    {
        foreach ([
            '/api/npCrates/1',
            '/api/npCrates',
            '/api/npCrates/1?include=labelledRecords',
            '/api/npCrates/2?fields[npCrates]=records',
            '/api/npShelves?include=crate',
            '/api/npShelves/1?include=crate,altCrate',
            '/api/npShelves?include=crate&fields[npCrates]=records',
            '/api/npRooms/1?include=shelves.crate',
            '/api/npRooms?include=shelves.crate',
            '/api/npRooms/1?include=pinned,things',
            '/api/npRooms/1/things',
            '/api/npRooms/1/pinned',
            '/api/npShelves/1/crate',
            '/api/npShelves/2/holder',
            '/api/npRecords/1/crates',
            '/api/npRecords/1?include=crates',
            '/api/npBins/1',
            '/api/npBins/1?include=records',
        ] as $uri) {
            $this->assertEveryLinkageCarriesItsPivot($this->document($uri), $uri);
        }
    }

    public function test_a_write_response_carries_the_pivot_of_every_linkage_it_renders(): void
    {
        $this->assertEveryLinkageCarriesItsPivot($this->write('PATCH', '/api/npBins/1', 200, ['data' => [
            'type' => 'npBins', 'id' => '1', 'attributes' => ['title' => 'Gamma'],
        ]]), 'update');

        $this->assertEveryLinkageCarriesItsPivot($this->write('PATCH', '/api/npBins/1?include=records', 200, ['data' => [
            'type' => 'npBins', 'id' => '1', 'attributes' => ['title' => 'Gamma'],
        ]]), 'update with an include');

        $document = $this->write('POST', '/api/npBins', 201, ['data' => [
            'type' => 'npBins',
            'attributes' => ['title' => 'Delta'],
            'relationships' => ['records' => ['data' => [['type' => 'npRecords', 'id' => '2', 'meta' => ['pivot' => ['position' => 4]]]]]],
        ]]);
        $this->assertEveryLinkageCarriesItsPivot($document, 'create');

        foreach (['PATCH', 'POST', 'DELETE'] as $method) {
            $linkage = $this->write($method, '/api/npBins/2/relationships/records', 200, ['data' => [['type' => 'npRecords', 'id' => '3', 'meta' => ['pivot' => ['position' => 7]]]]]);
            foreach ($this->arrayAt($linkage['data'] ?? null) as $member) {
                self::assertIsArray(\data_get($member, 'meta.pivot'), $method . ' ' . \json_encode($member));
            }
        }
    }

    public function test_a_rolled_back_atomic_batch_leaves_the_pivot_rows_as_they_were(): void
    {
        $before = $this->document('/api/npBins/1');

        $this->write('POST', '/api/operations', 404, ['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'npBins', 'id' => '1', 'relationship' => 'records'], 'data' => [['type' => 'npRecords', 'id' => '3', 'meta' => ['pivot' => ['position' => 7]]]]],
            ['op' => 'update', 'ref' => ['type' => 'npBins', 'id' => '99'], 'data' => ['type' => 'npBins', 'id' => '99', 'attributes' => ['title' => 'Nope']]],
        ]], self::ATOMIC_MEDIA_TYPE);

        self::assertSame(\data_get($before, 'data.relationships.records.data'), \data_get($this->document('/api/npBins/1'), 'data.relationships.records.data'));
    }

    public function test_an_action_response_carries_the_pivot_of_every_linkage_it_renders(): void
    {
        foreach ([
            ['/api/npCrates/1/-actions/muster', 200],
            ['/api/npShelves/2/-actions/muster?include=crate', 200],
            ['/api/npCrates/-actions/roll', 200],
            ['/api/npCrates/-actions/mix', 200],
            ['/api/npCrates/2/-actions/queue', 202],
        ] as [$uri, $status]) {
            $this->assertEveryLinkageCarriesItsPivot($this->action($uri, $status), $uri);
        }
    }

    public function test_an_atomic_result_carries_the_pivot_of_every_linkage_it_renders(): void
    {
        $document = $this->write('POST', '/api/operations', 200, ['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'npBins', 'id' => '2'], 'data' => ['type' => 'npBins', 'id' => '2', 'attributes' => ['title' => 'Beta']]],
            ['op' => 'add', 'data' => [
                'type' => 'npBins',
                'attributes' => ['title' => 'Delta'],
                'relationships' => ['records' => ['data' => [['type' => 'npRecords', 'id' => '1', 'meta' => ['pivot' => ['position' => 4]]]]]],
            ]],
        ]], self::ATOMIC_MEDIA_TYPE);

        foreach ([0, 1] as $index) {
            $this->assertEveryLinkageCarriesItsPivot(['data' => \data_get($document, 'atomic:results.' . $index . '.data')], 'atomic result ' . $index);
        }
    }

    /**
     * The `meta.pivot` bin 1's `keptRecords` relation renders for record `$record`.
     *
     * @return array<string, mixed>|null
     */
    protected function keptPivot(int $record): ?array
    {
        foreach (NestedPivotServiceProvider::LINKS as [$crate, $linkRecord, $position]) {
            if ($crate === 1 && $linkRecord === $record) {
                return $this->storesWrittenPivots() ? ['position' => $position] : null;
            }
        }

        self::fail(\sprintf('Bin 1 holds no record %d.', $record));
    }

    /**
     * The document a custom action answers with.
     *
     * @return array<mixed>
     */
    protected function action(string $uri, int $status = 200): array
    {
        $response = $this->call('POST', $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
        self::assertSame($status, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return $this->arrayAt($response->json());
    }

    /**
     * The `meta.pivot` a bin's `records` relation renders for a record at `$position`.
     *
     * @return array<string, mixed>
     */
    protected function binPivot(string $bin, int $record, int $position): array
    {
        $label = $bin . ' / ' . NestedPivotServiceProvider::RECORDS[$record];

        return $this->storesWrittenPivots() ? ['position' => $position, 'label' => $label] : ['label' => $label];
    }

    /**
     * The `meta.pivot` a record's `placedIn` relation renders for a crate holding it at `$position`.
     *
     * @return array<string, mixed>|null
     */
    protected function placedInPivot(int $position): ?array
    {
        return $this->storesWrittenPivots() ? ['position' => $position] : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    protected function write(string $method, string $uri, int $status, array $body, string $mediaType = self::MEDIA_TYPE): array
    {
        $response = $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], (string) \json_encode($body));
        self::assertSame($status, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return $this->arrayAt($response->json());
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
    protected function arrayAt(mixed $value): array
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
    private function primaryOf(array $document, string $type, string $id): array
    {
        foreach ($this->arrayAt($document['data'] ?? null) as $resource) {
            if (\data_get($resource, 'type') === $type && \data_get($resource, 'id') === $id) {
                return $this->arrayAt($resource);
            }
        }

        self::fail(\sprintf('No primary %s %s.', $type, $id));
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

    /**
     * Asserts that every member of every pivot relation's linkage the document renders, on
     * its primary data and its included resources, carries `meta.pivot`, and that at least one
     * such linkage rendered. A relation whose pivot fields are all stored renders none on a
     * provider that stores no pivot rows.
     *
     * @param array<mixed> $document
     */
    private function assertEveryLinkageCarriesItsPivot(array $document, string $context): void
    {
        $relations = ['npCrates' => ['records', 'labelledRecords'], 'npBins' => ['records'], 'npRecords' => $this->storesRecordPivots() ? ['placedIn'] : []];
        $data = $document['data'] ?? null;
        $resources = \is_array($data) ? (\array_is_list($data) ? $data : [$data]) : [];
        $checked = 0;
        foreach ([...$resources, ...$this->arrayAt($document['included'] ?? [])] as $resource) {
            $type = \data_get($resource, 'type');
            self::assertIsString($type);
            foreach ($relations[$type] ?? [] as $relation) {
                $relationship = \data_get($resource, 'relationships.' . $relation);
                if (!\is_array($relationship) || !\array_key_exists('data', $relationship)) {
                    continue;
                }
                foreach ($this->arrayAt($relationship['data']) as $member) {
                    $pivot = \data_get($member, 'meta.pivot');
                    $where = \sprintf('%s: %s %s %s member %s', $context, $type, \json_encode(\data_get($resource, 'id')), $relation, \json_encode($member));
                    self::assertIsArray($pivot, $where);
                    self::assertNotSame([], $pivot, $where);
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(0, $checked, $context . ' renders no pivot linkage: ' . \json_encode($document));
    }
}
