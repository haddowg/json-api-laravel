<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Member;
use Workbench\App\Models\Task;
use Workbench\App\Providers\RequiredRelationEloquentServiceProvider;
use Workbench\App\RequiredRelation\Fixtures;

/**
 * {@see RequiredRelationConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRequiredRelationConformanceTest extends RequiredRelationConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RequiredRelationEloquentServiceProvider::class;
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
        foreach (Fixtures::members() as $id => $name) {
            Member::query()->create(['id' => $id, 'name' => $name]);
        }
        foreach (Fixtures::tasks() as $id => $row) {
            Task::query()->create(['id' => $id, 'title' => $row['title'], 'owner_id' => $row['owner']])->watchers()->attach($row['watchers']);
        }
    }
}
