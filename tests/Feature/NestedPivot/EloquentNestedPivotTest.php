<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
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
        $single = $this->pivotQueries('/api/npShelves/1?include=crate.records,crate.labelledRecords');
        $collection = $this->pivotQueries('/api/npShelves?include=crate.records,crate.labelledRecords');

        self::assertSame(\count($single), \count($collection));
        self::assertSame(\count($single), \count($this->pivotQueries('/api/npRooms?include=shelves.crate.records,shelves.crate.labelledRecords')));
        foreach ($collection as $query) {
            self::assertStringNotContainsString('"crate_id" = ?', $query);
        }
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
