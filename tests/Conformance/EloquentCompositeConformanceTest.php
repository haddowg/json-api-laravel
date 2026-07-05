<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\CompositeWidget;
use Workbench\App\Providers\CompositeEloquentServiceProvider;

/**
 * {@see CompositeConformanceTestCase} against the reference Eloquent provider: each
 * composite attribute (Obj `address`, OneOf `block`, ArrayHash+Shape `contact`) is a
 * single `json` column with an `array` cast on {@see CompositeWidget} in an in-memory
 * SQLite database — so the same assertions witness that a composite value round-trips
 * real column storage, not just the in-memory array.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentCompositeConformanceTest extends CompositeConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CompositeEloquentServiceProvider::class;
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
        CompositeWidget::query()->create([
            'id' => 1,
            'name' => 'Seed',
            'address' => ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
        ]);
    }
}
