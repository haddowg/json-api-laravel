<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughAccess;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\Crate;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\CrateResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess\VaultResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ThroughAccessTestCase} over Eloquent, where global scopes narrow each `whereHas` hop.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentThroughAccessTest extends ThroughAccessTestCase
{
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
        foreach (['ta_vaults', 'ta_safes', 'ta_ledgers'] as $table) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code');
            });
        }
        Schema::create('ta_crates', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('vault_id')->nullable();
            $table->unsignedInteger('safe_id')->nullable();
            $table->unsignedInteger('ledger_id')->nullable();
            $table->unsignedInteger('auditor_id')->nullable();
        });
        Schema::create('ta_parcels', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('owner_id');
            $table->string('label');
            $table->unsignedInteger('crate_id')->nullable();
        });
        Schema::create('ta_tags', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('secret')->default(false);
        });
        Schema::create('ta_parcel_tag', static function (Blueprint $table): void {
            $table->unsignedInteger('parcel_id');
            $table->unsignedInteger('tag_id');
        });

        DB::table('ta_vaults')->insert([['id' => 1, 'code' => 'v1'], ['id' => 2, 'code' => 'v2']]);
        DB::table('ta_safes')->insert(['id' => 1, 'code' => 's1']);
        DB::table('ta_ledgers')->insert(['id' => 1, 'code' => 'l1']);
        DB::table('ta_crates')->insert([
            ['id' => 1, 'name' => 'alpha', 'vault_id' => 1, 'safe_id' => 1, 'ledger_id' => 1, 'auditor_id' => 1],
            ['id' => 2, 'name' => 'beta', 'vault_id' => 2, 'safe_id' => null, 'ledger_id' => null, 'auditor_id' => null],
            ['id' => 3, 'name' => 'gamma', 'vault_id' => null, 'safe_id' => null, 'ledger_id' => null, 'auditor_id' => null],
        ]);
        DB::table('ta_parcels')->insert([
            ['id' => 1, 'owner_id' => '1', 'label' => 'apple', 'crate_id' => 1],
            ['id' => 2, 'owner_id' => '2', 'label' => 'banana', 'crate_id' => 1],
            ['id' => 3, 'owner_id' => '1', 'label' => 'cherry', 'crate_id' => 2],
        ]);
        DB::table('ta_tags')->insert([['id' => 1, 'name' => 'red', 'secret' => false], ['id' => 2, 'name' => 'hidden', 'secret' => true]]);
        DB::table('ta_parcel_tag')->insert([['parcel_id' => 1, 'tag_id' => 1], ['parcel_id' => 2, 'tag_id' => 2], ['parcel_id' => 3, 'tag_id' => 2]]);
    }

    #[Test]
    public function theExistsAdmitsNoRowThroughARefusedRelation(): void
    {
        $inventories = ['crates' => (new CrateResource())->allFields(), 'vaults' => (new VaultResource())->allFields()];
        $paths = new FilterPaths(new ThroughPathResolver(static fn(string $type): ?array => $inventories[$type] ?? null), $inventories['crates'], 'crates');
        $filter = $paths->resolve(WhereThrough::make('vaultCode', 'vault.code')->build());
        $vault = $paths->hopAt('vault')?->relation;
        self::assertInstanceOf(RelationInterface::class, $vault);
        $handler = new EloquentFilterHandler();

        $ids = function (FilterPaths $scoped) use ($handler, $filter): array {
            $query = $this->newQuery();
            $handler->forPaths($scoped)->apply($filter, $query, 'v1');

            return \array_map(static fn(mixed $id): int => \is_numeric($id) ? (int) $id : 0, $query->pluck('id')->all());
        };

        self::assertSame([], $ids($paths->scoped([$vault])));
        self::assertSame([1], $ids($paths->scoped()));
    }

    /**
     * @param class-string<Model> $class
     *
     * @return Builder<Model>
     */
    private function newQuery(string $class = Crate::class): Builder
    {
        return (new $class())->newQuery();
    }
}
