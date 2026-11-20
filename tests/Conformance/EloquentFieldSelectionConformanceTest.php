<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\FieldSelection\Curator as CuratorSeed;
use Workbench\App\FieldSelection\Panel as PanelSeed;
use Workbench\App\Models\Curator;
use Workbench\App\Models\Panel;
use Workbench\App\Providers\FieldSelectionEloquentServiceProvider;

/**
 * The field-selection conformance cases over the Eloquent provider, each object a real
 * `json` column in in-memory SQLite. The provider loads the model whole and core trims
 * the value on render.
 */
#[CoversNothing]
final class EloquentFieldSelectionConformanceTest extends FieldSelectionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return FieldSelectionEloquentServiceProvider::class;
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
        Curator::query()->create(['id' => 1, 'name' => 'Ada', 'stats' => CuratorSeed::SEED_STATS, 'office' => CuratorSeed::SEED_OFFICE, 'exhibits' => CuratorSeed::SEED_EXHIBITS]);
        Panel::query()->create([
            'id' => 1,
            'name' => 'Seed',
            'values' => PanelSeed::SEED_VALUES,
            'groups' => PanelSeed::SEED_GROUPS,
            'settings' => PanelSeed::SEED_SETTINGS,
            'address' => PanelSeed::SEED_ADDRESS,
            'rows' => PanelSeed::SEED_ROWS,
            'archive' => [],
            'tags' => PanelSeed::SEED_TAGS,
            'blocks' => PanelSeed::SEED_BLOCKS,
            'hero' => PanelSeed::SEED_HERO,
            'curator_id' => 1,
        ]);
    }
}
