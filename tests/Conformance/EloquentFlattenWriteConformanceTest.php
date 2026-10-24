<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\Flatten\Author;
use haddowg\JsonApiLaravel\Tests\Fixtures\Flatten\Book;
use haddowg\JsonApiLaravel\Tests\Fixtures\Flatten\Country;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see FlattenWriteConformanceTestCase} against the Eloquent reference pair over SQLite, plus
 * the statements a flattened write issues: one UPDATE per changed model, none for a model the
 * write left as it was.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentFlattenWriteConformanceTest extends FlattenWriteConformanceTestCase
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
        parent::defineEnvironment($app);

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
        Schema::create('fl_countries', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fl_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('country_id')->nullable();
        });
        Schema::create('fl_books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('editor_id')->nullable();
        });

        Country::query()->create(['name' => 'Wonderland']);
        Author::query()->create(['name' => 'Ada Lovelace', 'country_id' => 1]);
        Author::query()->create(['name' => 'Grace Hopper', 'country_id' => 1]);
        Book::query()->create(['title' => 'Algorithms', 'author_id' => 1, 'editor_id' => 1]);
        Book::query()->create(['title' => 'Compilers', 'author_id' => 2, 'editor_id' => 2]);
        Book::query()->create(['title' => 'Orphan']);
    }

    #[Test]
    public function aFlattenedWriteUpdatesOnlyTheModelItChanged(): void
    {
        $updates = $this->updatesDuring(fn() => $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']])->assertOk());

        self::assertCount(1, $updates);
        self::assertStringContainsString('"fl_authors"', $updates[0]);
    }

    #[Test]
    public function aMultiHopFlattenedWriteUpdatesOnlyTheLastHop(): void
    {
        $updates = $this->updatesDuring(fn() => $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorCountry' => 'Oz']])->assertOk());

        self::assertCount(1, $updates);
        self::assertStringContainsString('"fl_countries"', $updates[0]);
    }

    #[Test]
    public function anUnchangedFlattenedValueIssuesNoUpdate(): void
    {
        $updates = $this->updatesDuring(fn() => $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada Lovelace', 'authorCountry' => 'Wonderland']])->assertOk());

        self::assertSame([], $updates);
    }

    /**
     * The UPDATE statements `$work` sends to the database.
     *
     * @return list<string>
     */
    private function updatesDuring(\Closure $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();
        } finally {
            DB::disableQueryLog();
        }

        $updates = [];
        foreach (DB::getQueryLog() as $entry) {
            if (\str_starts_with(\strtolower($entry['query']), 'update')) {
                $updates[] = $entry['query'];
            }
        }

        return $updates;
    }
}
