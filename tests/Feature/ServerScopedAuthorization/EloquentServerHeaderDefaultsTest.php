<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ServerHeaderDefaultsTestCase} over the reference Eloquent provider on SQLite.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentServerHeaderDefaultsTest extends ServerHeaderDefaultsTestCase
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
        Schema::create('ssa_documents', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('ssa_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->unsignedInteger('document_id');
        });

        Schema::create('ssa_labels', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        DB::table('ssa_labels')->insert(['id' => 1, 'name' => 'Draft']);
        DB::table('ssa_documents')->insert(['id' => 1, 'title' => 'Handbook']);
        DB::table('ssa_notes')->insert([
            ['id' => 1, 'body' => 'First', 'document_id' => 1],
            ['id' => 2, 'body' => 'Second', 'document_id' => 1],
        ]);
    }
}
