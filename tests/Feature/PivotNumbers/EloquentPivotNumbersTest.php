<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotNumbers;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers\PivotNumbersEloquentServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\LinkRows\Fixtures;
use Workbench\App\Models\Board;
use Workbench\App\Models\Note;
use Workbench\App\Providers\LinkRowsEloquentServiceProvider;

/**
 * {@see PivotNumbersTestCase} on the Eloquent provider.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotNumbersTest extends PivotNumbersTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, LinkRowsEloquentServiceProvider::class, PivotNumbersEloquentServiceProvider::class];
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Fixtures::notes() as $id => $title) {
            Note::query()->create(['id' => $id, 'title' => $title]);
        }
        Board::query()->create(['id' => 1, 'name' => 'Weekend']);
    }
}
