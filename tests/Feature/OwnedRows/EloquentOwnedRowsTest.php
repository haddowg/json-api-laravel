<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OwnedRows;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see OwnedRowsTestCase} with an Eloquent global scope on the `tasks` model.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentOwnedRowsTest extends OwnedRowsTestCase
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
        Schema::create('or_projects', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('or_tasks', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('owner_id');
            $table->unsignedInteger('project_id');
        });

        DB::table('or_projects')->insert(['id' => 1, 'name' => 'Launch']);
        DB::table('or_tasks')->insert([
            ['id' => 1, 'title' => 'Draft', 'owner_id' => 1, 'project_id' => 1],
            ['id' => 2, 'title' => 'Review', 'owner_id' => 2, 'project_id' => 1],
            ['id' => 3, 'title' => 'Ship', 'owner_id' => 1, 'project_id' => 1],
        ]);
    }
}
