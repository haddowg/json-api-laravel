<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage\EloquentFillUsingLinkageServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see FillUsingLinkageConformanceTestCase} against the reference Eloquent provider.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentFillUsingLinkageConformanceTest extends FillUsingLinkageConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return EloquentFillUsingLinkageServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('fl_labels', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fl_memos', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('setting_id')->nullable();
            $table->json('setting_ids')->nullable();
            $table->string('label_id')->nullable();
            $table->json('label_ids')->nullable();
        });
        Schema::create('fl_stickies', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('label_id')->nullable();
        });

        DB::table('fl_labels')->insert([['id' => 1, 'name' => 'urgent'], ['id' => 2, 'name' => 'ux']]);
        DB::table('fl_memos')->insert(['id' => 1, 'title' => 'Todo', 'setting_id' => '1', 'setting_ids' => '["1"]', 'label_id' => '1', 'label_ids' => '["1"]']);
    }

    protected function storedCount(string $type): int
    {
        return DB::table($type === 'memos' ? 'fl_memos' : 'fl_stickies')->count();
    }
}
