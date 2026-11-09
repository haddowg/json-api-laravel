<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Player;
use Workbench\App\Models\Squad;
use Workbench\App\Providers\RelationBoundsEloquentServiceProvider;
use Workbench\App\RelationBounds\Fixtures;

/**
 * {@see RelationBoundsConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationBoundsConformanceTest extends RelationBoundsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelationBoundsEloquentServiceProvider::class;
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        foreach (Fixtures::players() as $id => $name) {
            Player::query()->create(['id' => $id, 'name' => $name]);
        }
        foreach (Fixtures::squads() as $id => $row) {
            Squad::query()->create(['id' => $id, 'name' => $row['name']])->players()->attach($row['players']);
        }
    }
}
