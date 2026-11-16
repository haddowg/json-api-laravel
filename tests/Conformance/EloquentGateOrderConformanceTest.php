<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\GateOrder\GateLog;
use Workbench\App\Models\GateWidget;
use Workbench\App\Providers\GateOrderEloquentServiceProvider;

/**
 * {@see GateOrderConformanceTestCase} on Eloquent over SQLite, plus the check on moving a
 * child out of another parent, which only the Eloquent persister reports.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentGateOrderConformanceTest extends GateOrderConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return GateOrderEloquentServiceProvider::class;
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
        GateWidget::query()->create(['id' => 1, 'name' => 'mine', 'owner' => 'user', 'partner_id' => 2]);
        GateWidget::query()->create(['id' => 2, 'name' => 'theirs', 'owner' => 'other']);
        GateWidget::query()->create(['id' => 3, 'name' => 'secret', 'owner' => 'user']);
        GateWidget::query()->create(['id' => 4, 'name' => 'child', 'owner' => 'other', 'parent_id' => 2]);
    }

    #[Test]
    public function aRefusedMoveOutOfAnotherParentRunsNoHook(): void
    {
        // The caller may change widget 1, but widget 4 sits under widget 2, which they may
        // not change, and is not theirs either.
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument(['data' => [['type' => 'gateWidgets', 'id' => '4']]])->post('/api/gateWidgets/1/relationships/children'));
        self::assertSame(2, GateWidget::query()->findOrFail(4)->parent_id);
        self::assertSame([], GateLog::$entries);
    }
}
