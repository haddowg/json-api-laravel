<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedConversions;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\ScopedConversionsEloquentServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Board;

/**
 * {@see ScopedConversionsTestCase} on the Eloquent provider.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentScopedConversionsTest extends ScopedConversionsTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ScopedConversionsEloquentServiceProvider::class];
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
        Board::query()->create(['id' => 1, 'name' => 'Weekend']);
    }
}
