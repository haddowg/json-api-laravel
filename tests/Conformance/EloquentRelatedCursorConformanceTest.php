<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see RelatedCursorConformanceTestCase} against the **reference Eloquent provider**
 * executed as real SQL over in-memory SQLite: the `cursorGroups.widgets` HasMany's
 * parent-scoped query carries the keyset WHERE + the forced NULL=largest ORDER BY on
 * top of the FK constraint. It seeds the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::cursorGroups()} partition the
 * in-memory witness carries ({@see CursorWidgetSeeder} then {@see CursorGroupSeeder}),
 * so every inherited assertion must produce the IDENTICAL parent-scoped keyset page
 * (docs/adr/0016, bundle ADR 0063).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelatedCursorConformanceTest extends RelatedCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelatedCursorConformanceEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
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
        (new CursorWidgetSeeder())->run();
        (new CursorGroupSeeder())->run();
    }
}
