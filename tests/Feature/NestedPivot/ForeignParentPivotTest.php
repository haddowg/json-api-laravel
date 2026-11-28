<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\PivotParentNotAModel;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NestedPivotServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpCrateResource;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A custom provider serves the crates while the Eloquent provider serves their records, so
 * the Eloquent pivot read has no model to join from and refuses by name on first use.
 *
 * @internal
 */
#[CoversClass(PivotParentNotAModel::class)]
final class ForeignParentPivotTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('nested_pivot.foreign_crates', true);
        $config->set('app.debug', true);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        return [JsonApiServiceProvider::class, NestedPivotServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        NestedPivotServiceProvider::migrate();
    }

    public function test_the_related_endpoint_names_the_parent_provider(): void
    {
        $response = $this->get('/api/npCrates/1/records', ['Accept' => 'application/vnd.api+json'])->assertStatus(500);

        self::assertSame(PivotParentNotAModel::class, $response->json('errors.0.meta.exception'));
        $message = $response->json('errors.0.detail');
        self::assertIsString($message);
        self::assertStringContainsString('The relation "records" reads its pivot rows through ' . EloquentDataProvider::class, $message);
        self::assertStringContainsString('of type "npCrates", served by ' . InMemoryDataProvider::class, $message);
        self::assertStringContainsString('implements haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface', $message);
    }

    public function test_the_single_parent_and_batched_reads_name_the_parent_provider(): void
    {
        $providers = $this->app?->make(DataProviderRegistry::class);
        self::assertInstanceOf(DataProviderRegistry::class, $providers);
        $records = $providers->forType('npRecords');
        self::assertInstanceOf(EloquentDataProvider::class, $records);
        $crate = $providers->forType('npCrates')->fetchOne('npCrates', '1');
        self::assertNotNull($crate);
        $relation = null;
        foreach ((new NpCrateResource())->allFields() as $field) {
            if ($field instanceof BelongsToMany && $field->name() === 'records') {
                $relation = $field;
            }
        }
        self::assertNotNull($relation);

        foreach ([
            static fn(): array => $records->fetchRelatedPivotMap('npCrates', 'npRecords', $crate, $relation),
            static fn(): array => $records->fetchRelatedPivotMapBatch('npCrates', 'npRecords', [$crate], $relation),
        ] as $read) {
            try {
                $read();
                self::fail('The read went through.');
            } catch (PivotParentNotAModel $e) {
                self::assertStringContainsString('served by ' . InMemoryDataProvider::class, $e->getMessage());
            }
        }
    }
}
