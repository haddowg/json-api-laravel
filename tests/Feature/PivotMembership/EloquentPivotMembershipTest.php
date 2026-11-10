<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotMembership;

use haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership\PivotMembershipServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The records are read off a `belongsToMany` with a custom pivot class, which yields the
 * records, each carrying its link row.
 *
 * @internal
 */
final class EloquentPivotMembershipTest extends PivotMembershipTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    protected function storedRecords(int $crate): array
    {
        $ids = \array_map(
            static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '',
            DB::table('pm_crate_records')->where('pm_crate_id', $crate)->pluck('pm_record_id')->all(),
        );
        \sort($ids);

        return $ids;
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
        Schema::create('pm_records', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('pm_crates', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('pm_crate_records', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pm_crate_id');
            $table->unsignedInteger('pm_record_id');
            $table->integer('slot')->nullable();
        });

        foreach (PivotMembershipServiceProvider::RECORDS as [$id, $title, $hidden]) {
            DB::table('pm_records')->insert(['id' => $id, 'title' => $title, 'hidden' => $hidden]);
        }
        foreach (PivotMembershipServiceProvider::CRATES as [$id, $name, $links]) {
            DB::table('pm_crates')->insert(['id' => $id, 'name' => $name]);
            foreach ($links as [$row, $record]) {
                DB::table('pm_crate_records')->insert(['id' => $row, 'pm_crate_id' => $id, 'pm_record_id' => $record]);
            }
        }
    }
}
