<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ActionFilters;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApiLaravel\Action\FilteredCollection;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\Gadget;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\GadgetResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ActionFiltersTestCase} over Eloquent, where a global scope hides archived gadgets.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentActionFiltersTest extends ActionFiltersTestCase
{
    #[Test]
    public function theFilteredRowsKeepTheModelsGlobalScopes(): void
    {
        // Dynamo is red and large but archived.
        $response = $this->countAction('?filter[colour]=red');

        self::assertSame(['1'], $this->ids($response));
        self::assertSame(['1'], $response->json('meta.queryIds'));
    }

    #[Test]
    public function queryReturnsAFreshFilteredBuilderEachCall(): void
    {
        $filtered = $this->filtered(['colour' => 'red', 'size' => 'small']);

        $first = $filtered->query();
        self::assertInstanceOf(Builder::class, $first);
        self::assertNotSame($first, $filtered->query());
        self::assertSame([3], $first->pluck('id')->all());
        self::assertSame([], $first->getQuery()->orders ?? []);
        self::assertNull($first->getQuery()->limit);

        $this->paintAction('')->assertJsonPath('meta.builder', true);
    }

    #[Test]
    public function eachReadsInIdOrderedBatches(): void
    {
        DB::enableQueryLog();

        $ids = [];
        foreach ($this->filtered(['size' => 'large'])->each(1) as $gadget) {
            self::assertInstanceOf(Gadget::class, $gadget);
            $ids[] = $gadget->id;
        }

        self::assertSame([1, 5], $ids);
        // One read per row, then an empty one ends the walk.
        self::assertCount(3, DB::getQueryLog());
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filtered(array $filter): FilteredCollection
    {
        return new FilteredCollection(
            new EloquentDataProvider(['gadgets' => Gadget::class]),
            'gadgets',
            new CollectionCriteria(
                new QueryParameters(fields: [], includes: [], sort: [], filter: $filter, pagination: []),
                (new GadgetResource())->allFilters(),
            ),
        );
    }

    protected function store(): string
    {
        return 'eloquent';
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
        Schema::create('af_gadgets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('colour');
            $table->unsignedInteger('weight');
            $table->string('size');
            $table->boolean('archived')->default(false);
        });

        DB::table('af_gadgets')->insert([
            ['id' => 1, 'name' => 'Anvil', 'colour' => 'red', 'weight' => 50, 'size' => 'large', 'archived' => false],
            ['id' => 2, 'name' => 'Bolt', 'colour' => 'blue', 'weight' => 1, 'size' => 'small', 'archived' => false],
            ['id' => 3, 'name' => 'Crank', 'colour' => 'red', 'weight' => 5, 'size' => 'small', 'archived' => false],
            ['id' => 4, 'name' => 'Dynamo', 'colour' => 'red', 'weight' => 20, 'size' => 'large', 'archived' => true],
            ['id' => 5, 'name' => 'Easel', 'colour' => 'green', 'weight' => 8, 'size' => 'large', 'archived' => false],
        ]);
    }
}
