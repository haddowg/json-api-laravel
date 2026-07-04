<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\CatalogItem;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\Owner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Referee for `?withCount` over a **camelCase** relation method. Eloquent names the default
 * withCount alias `Str::snake("$name count")`, so a `catalogItems` relation yields the column
 * `catalog_items_count` — which the provider's `getAttribute("catalogItems_count")` would
 * miss, coercing every parent's count to a silent `0`. The provider now requests an explicit
 * `catalogItems as catalogItems_count` alias; this fixture proves the true counts survive
 * (and would read 0 without the fix), the divergence the single-word music-catalog relations
 * (`albums`) never exercise.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentCamelCaseWithCountTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('cc_owners', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('cc_catalog_items', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
            $table->string('title');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cc_owners')->insert([
            ['id' => 1, 'name' => 'owns two'],
            ['id' => 2, 'name' => 'owns none'],
        ]);
        DB::table('cc_catalog_items')->insert([
            ['id' => 1, 'owner_id' => 1, 'title' => 'a'],
            ['id' => 2, 'owner_id' => 1, 'title' => 'b'],
        ]);
    }

    public function test_count_related_reads_the_camel_case_alias(): void
    {
        $provider = new EloquentDataProvider(['owners' => Owner::class, 'catalogItems' => CatalogItem::class]);

        /** @var list<Owner> $owners */
        $owners = Owner::query()->orderBy('id')->get()->all();

        $counts = $provider->countRelated(
            'owners',
            $owners,
            $this->catalogItemsRelation(),
            new CollectionCriteria(new QueryParameters([], [], [], [], [])),
            $this->createStub(JsonApiRequestInterface::class),
        );

        // The true counts — 2 for owner 1, zero-filled 0 for owner 2 — NOT the silent 0 the
        // snake_cased default alias would produce.
        self::assertSame(['1' => 2, '2' => 0], $counts);
    }

    private function catalogItemsRelation(): RelationInterface
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'owners';

            public function fields(): array
            {
                return [
                    Id::make(),
                    HasMany::make('catalogItems', 'catalogItems')->countable(),
                ];
            }
        };

        $relation = $resource->relationNamed('catalogItems');
        self::assertInstanceOf(RelationInterface::class, $relation);

        return $relation;
    }
}
