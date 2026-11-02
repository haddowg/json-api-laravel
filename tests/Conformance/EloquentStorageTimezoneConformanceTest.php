<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\StorageTimezoneEloquentServiceProvider;
use Workbench\App\StorageTimezone\ShiftFixtures;

/**
 * {@see StorageTimezoneConformanceTestCase} on Eloquent, plus checks on the local time
 * that lands in the column.
 */
#[CoversNothing]
final class EloquentStorageTimezoneConformanceTest extends StorageTimezoneConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return StorageTimezoneEloquentServiceProvider::class;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

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
        DB::table('tz_shifts')->insert(ShiftFixtures::rows());
        DB::table('tz_workers')->insert(['id' => 1, 'name' => 'Ana']);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotDateTimeIsStoredAsLocalTime(): void
    {
        $this->jsonApi()->withDocument(['data' => [
            ['type' => 'workers', 'id' => '1', 'meta' => ['pivot' => ['checkedInAt' => '2026-10-01T16:00:00Z']]],
        ]])->post('/api/shifts/1/relationships/workers')->assertSuccessful();

        self::assertSame('2026-10-01 12:00:00', DB::table('tz_shift_worker')->where(['shift_id' => 1, 'worker_id' => 1])->value('checked_in_at'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteInUtcIsStoredAsLocalTime(): void
    {
        $created = $this->write('POST', '/api/shifts', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        $created->assertCreated();

        self::assertSame('2026-10-03 12:00:00', DB::table('tz_shifts')->where('id', $created->json('data.id'))->value('starts_at'));
    }

    #[Test]
    #[Group('crud:create')]
    public function theStoredLocalTimeFollowsTheDefaultTimezoneAtWriteTime(): void
    {
        \date_default_timezone_set('Europe/Paris');

        $created = $this->write('POST', '/api/shifts', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        $created->assertCreated();

        self::assertSame('2026-10-03 18:00:00', DB::table('tz_shifts')->where('id', $created->json('data.id'))->value('starts_at'));
    }
}
