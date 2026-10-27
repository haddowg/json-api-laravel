<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks\EloquentActionLinksServiceProvider;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EloquentActionLinksConformanceTest extends ActionLinksConformanceTestCase
{
    protected function fixtureServiceProvider(): string
    {
        return EloquentActionLinksServiceProvider::class;
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

    protected function seedRecords(): void
    {
        foreach (['First' => 'released', 'Second' => 'released', 'Third' => 'withdrawn', 'Fourth' => 'released', 'Fifth' => 'withdrawn'] as $title => $status) {
            DB::table('albums')->insert(['title' => $title, 'status' => $status, 'released_at' => '2020-01-01 00:00:00']);
        }
    }
}
