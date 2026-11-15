<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\EloquentMutationsServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many write on the Eloquent provider resolves its members with one query per related
 * type: the existence check, the owners a move must be authorized against and the members it
 * re-parents each read their rows in one query, so adding 2 posts to an author reads as many
 * times as adding 3. Writing each moved row is one `UPDATE` per row, as on any ORM.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentToManyWriteQueryBudgetTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, EloquentMutationsServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('mut_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('mut_posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('sponsor_id')->nullable();
            $table->unsignedInteger('moderator_id')->nullable();
            $table->unsignedInteger('feature_id')->nullable();
            $table->string('feature_type')->nullable();
        });

        DB::table('mut_authors')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
        foreach ([1, 2, 3, 4, 5] as $id) {
            DB::table('mut_posts')->insert(['id' => $id, 'title' => 'Post ' . $id]);
        }
    }

    #[Test]
    public function addingMembersReadsTheSameNumberOfTimesWhateverTheirCount(): void
    {
        $selects = [];
        foreach (['1' => ['1', '2'], '2' => ['3', '4', '5']] as $author => $posts) {
            $statements = [];
            DB::listen(static function (QueryExecuted $query) use (&$statements): void {
                $statements[] = $query->sql;
            });
            $response = $this->json('POST', '/api/authors/' . $author . '/relationships/posts', [
                'data' => \array_map(static fn(string $id): array => ['type' => 'posts', 'id' => $id], $posts),
            ], ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $selects[] = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')));
            DB::flushQueryLog();
            DB::getEventDispatcher()?->forget(QueryExecuted::class);
        }

        self::assertSame(\count($selects[0]), \count($selects[1]), \sprintf("2 posts read:\n%s\n3 posts read:\n%s", \implode("\n", $selects[0]), \implode("\n", $selects[1])));
    }
}
