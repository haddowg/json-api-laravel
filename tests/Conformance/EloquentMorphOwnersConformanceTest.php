<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners\MorphOwnersServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class EloquentMorphOwnersConformanceTest extends MorphOwnersConformanceTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    #[Test]
    public function optimizeRefusesASortThroughAForeignKeyAndPassesTheFiltersThroughOne(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $warmer = $app->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);
        $problems = \implode("\n", $warmer->warm());

        self::assertStringContainsString('declares the sort "noteBody" reaching through "note", which the Eloquent provider cannot run: "note" is read through the foreign key "owner"', $problems);
        self::assertStringNotContainsString('the filter', $problems);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $driver = \getenv('JSONAPI_TEST_DB');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => '127.0.0.1',
                'port' => (int) \getenv('JSONAPI_TEST_DB_PORT'),
                'database' => 'jsonapi',
                'username' => $driver === 'mysql' ? 'root' : 'postgres',
                'password' => 'secret',
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['mo_notes', 'mo_posts', 'mo_videos'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('mo_posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('mo_videos', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('mo_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->string('owner_type')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('author_type')->nullable();
            $table->unsignedInteger('author_id')->nullable();
        });

        $seed = MorphOwnersServiceProvider::seed();
        foreach ($seed['posts'] as [$id, $title]) {
            DB::table('mo_posts')->insert(['id' => $id, 'title' => $title]);
        }
        foreach ($seed['videos'] as [$id, $title]) {
            DB::table('mo_videos')->insert(['id' => $id, 'title' => $title]);
        }
        foreach ($seed['notes'] as [$id, $body, $ownerType, $ownerId, $authorType, $authorId]) {
            DB::table('mo_notes')->insert(['id' => $id, 'body' => $body, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'author_type' => $authorType, 'author_id' => $authorId]);
        }
    }
}
