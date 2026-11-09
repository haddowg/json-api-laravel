<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies\FilterFamiliesServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ListFilterAndFamilyConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentListFilterAndFamilyConformanceTest extends ListFilterAndFamilyConformanceTestCase
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
        Schema::create('ff_shelves', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('ff_books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('shelf_id');
            $table->string('title');
            $table->string('genre');
            $table->unsignedInteger('pages');
        });

        Schema::create('ff_reading_lists', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('ff_reading_list_book', static function (Blueprint $table): void {
            $table->unsignedInteger('reading_list_id');
            $table->unsignedInteger('book_id');
            $table->unsignedInteger('rank');
        });

        DB::table('ff_reading_lists')->insert(['id' => 1, 'name' => 'Favourites']);
        foreach (FilterFamiliesServiceProvider::ranks() as $bookId => $rank) {
            DB::table('ff_reading_list_book')->insert(['reading_list_id' => 1, 'book_id' => $bookId, 'rank' => $rank]);
        }
        foreach (FilterFamiliesServiceProvider::shelves() as $id => $name) {
            DB::table('ff_shelves')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (FilterFamiliesServiceProvider::books() as $row) {
            DB::table('ff_books')->insert([
                'id' => $row['id'],
                'shelf_id' => $row['shelf'],
                'title' => $row['title'],
                'genre' => $row['genre'],
                'pages' => $row['pages'],
            ]);
        }
    }
}
