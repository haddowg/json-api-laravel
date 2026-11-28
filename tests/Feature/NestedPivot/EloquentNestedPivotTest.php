<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpCrate;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpCrateResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see NestedPivotTestCase} on the Eloquent provider, which renders the stored pivot columns
 * beside the computed field and reads them with the include's own queries.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentNestedPivotTest extends NestedPivotTestCase
{
    use SchemaConformanceTrait;

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

    protected function storesPivots(): bool
    {
        return true;
    }

    public function test_the_documents_match_the_generated_schema(): void
    {
        $headers = ['Accept' => 'application/vnd.api+json'];

        $this->assertResponseMatchesGeneratedSchema($this->get('/api/npCrates/1?include=records,labelledRecords', $headers)->assertOk(), 'npCrates', SchemaDocumentKind::Single);
        $this->assertResponseMatchesGeneratedSchema($this->get('/api/npShelves?include=crate.records,altCrate.labelledRecords', $headers)->assertOk(), 'npShelves', SchemaDocumentKind::Collection);
        $this->assertResponseMatchesGeneratedSchema($this->get('/api/npRooms/1?include=shelves.crate.records', $headers)->assertOk(), 'npRooms', SchemaDocumentKind::Single);
    }

    public function test_the_pivot_rides_the_include_queries_whatever_the_number_of_parents(): void
    {
        $shelves = $this->pivotQueries('/api/npShelves?include=crate.records,crate.labelledRecords');

        self::assertCount(\count($shelves), $this->pivotQueries('/api/npRooms?include=shelves.crate.records,shelves.crate.labelledRecords'));
        foreach ([...$shelves, ...$this->pivotQueries('/api/npShelves/1?include=crate.records,altCrate.records')] as $query) {
            self::assertStringNotContainsString('"crate_id" = ?', $query);
            self::assertStringNotContainsString('"record_id" = ?', $query);
        }
    }

    public function test_a_with_data_relation_the_fieldset_leaves_out_is_not_windowed(): void
    {
        $accept = ['Accept' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'];
        $query = '/api/npBins/1?relatedQuery[records][sort]=-position';

        // records renders data unasked, so the profile windows it in its relatedQuery order;
        // keptRecords, never sparse, renders whatever the fieldset.
        $sorted = static fn(array $queries): array => \array_values(\array_filter($queries, static fn(string $query): bool => \str_contains($query, '"pivot_position" desc') || \str_contains($query, '"position" desc')));
        self::assertCount(1, $sorted($this->profileQueries($query, $accept)));
        self::assertSame([], $sorted($this->profileQueries($query . '&fields[npBins]=title', $accept)));
        $this->get($query . '&relatedQuery[records][filter][nope]=1&fields[npBins]=title', $accept)->assertStatus(400);
    }

    /**
     * The join-table queries a profile read runs.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function profileQueries(string $uri, array $headers): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri, $headers)->assertOk();
        $queries = \array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return \array_values(\array_filter($queries, static fn(string $query): bool => \str_contains($query, 'np_crate_record')));
    }

    public function test_the_provider_reads_every_parents_pivot_rows_in_one_query(): void
    {
        $provider = new EloquentDataProvider(['npCrates' => NpCrate::class, 'npRecords' => NpRecord::class]);
        $relation = null;
        foreach ((new NpCrateResource())->allFields() as $field) {
            if ($field instanceof BelongsToMany && $field->name() === 'records') {
                $relation = $field;
            }
        }
        self::assertNotNull($relation);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $maps = $provider->fetchRelatedPivotMapBatch('npCrates', 'npRecords', \array_values(NpCrate::query()->orderBy('id')->get()->all()), $relation);
        $queries = \count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame(2, $queries);
        self::assertSame(['1', '2'], \array_map('strval', \array_keys($maps)));
        self::assertSame(['1', '2'], \array_map('strval', \array_keys($maps['1'] ?? [])));
        self::assertSame(5, $maps['2']['1']['position'] ?? null);
        self::assertInstanceOf(\DateTimeInterface::class, $maps['2']['3']['addedAt'] ?? null);
    }

    /**
     * The queries a read runs against the join table.
     *
     * @return list<string>
     */
    private function pivotQueries(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->document($uri);
        $queries = \array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return \array_values(\array_filter($queries, static fn(string $query): bool => \str_contains($query, 'np_crate_record')));
    }
}
