<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Preset;
use Workbench\App\Providers\UnionEloquentServiceProvider;

/**
 * The union conformance cases over the Eloquent provider, in in-memory SQLite, each
 * union a `json` column.
 */
#[CoversNothing]
final class EloquentUnionConformanceTest extends UnionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return UnionEloquentServiceProvider::class;
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
        Preset::query()->create(['id' => 1, 'value' => 'gold', 'label' => 'Seed']);
    }

    #[Test]
    public function eachColumnHoldsTheUnionsStoredForm(): void
    {
        $id = $this->createdId(['value' => ['a', 2, true], 'label' => false, 'due' => '2026-01-02', 'at' => '2026-01-02T03:04:05+02:00', 'extra' => ['limit' => '2026-03-04']]);
        $row = (array) DB::table('presets')->where('id', $id)->first();

        self::assertSame('["a",2.0,true]', $row['value'] ?? null);
        self::assertSame('false', $row['label'] ?? null);
        self::assertSame('"2026-01-02"', $row['due'] ?? null);
        self::assertSame('"2026-01-02T03:04:05+02:00"', $row['at'] ?? null);
        self::assertSame('{"limit":"2026-03-04"}', $row['extra'] ?? null);
    }

    #[Test]
    public function aNullUnionIsAnSqlNull(): void
    {
        $id = $this->createdId(['value' => null, 'due' => null]);
        $row = (array) DB::table('presets')->where('id', $id)->first();

        self::assertNull($row['value'] ?? null);
        self::assertNull($row['due'] ?? null);
    }
}
