<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorph;

use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph\Agency;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph\IdentifiedMorphServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see IdentifiedMorphTestCase} over Eloquent, with a global scope hiding advertiser 3.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentIdentifiedMorphTest extends IdentifiedMorphTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    protected function queries(\Closure $read): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $read();
        } finally {
            $connection->disableQueryLog();
        }

        return \array_map(static fn(array $query): string => (string) $query['query'], $connection->getQueryLog());
    }

    protected function agencyClass(): string
    {
        return Agency::class;
    }

    protected function addCampaign(int $id, string $type, int $target): void
    {
        DB::table('im_campaigns')->insert(['id' => $id, 'name' => 'Campaign ' . $id, 'target_type' => $type, 'target_id' => $target]);
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
        Schema::create('im_people', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('im_advertisers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('owner_id');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('im_agencies', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('owner_id');
        });
        Schema::create('im_campaigns', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('target_type')->nullable();
            $table->unsignedInteger('target_id')->nullable();
        });

        DB::table('im_people')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob']]);
        DB::table('im_advertisers')->insert([
            ['id' => 1, 'name' => 'Acme', 'owner_id' => 1, 'hidden' => false],
            ['id' => 2, 'name' => 'Globex', 'owner_id' => 2, 'hidden' => false],
            ['id' => 3, 'name' => 'Shadow', 'owner_id' => 1, 'hidden' => true],
        ]);
        DB::table('im_agencies')->insert([['id' => 1, 'name' => 'Initech', 'owner_id' => 2], ['id' => 2, 'name' => 'Umbrella', 'owner_id' => 1]]);
        foreach (IdentifiedMorphServiceProvider::CAMPAIGNS as $id => [$type, $target]) {
            DB::table('im_campaigns')->insert(['id' => $id, 'name' => 'Campaign ' . $id, 'target_type' => $type, 'target_id' => $target]);
        }
    }
}
