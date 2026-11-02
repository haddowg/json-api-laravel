<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\StorageTimezoneEloquentServiceProvider;
use Workbench\App\StorageTimezone\ShiftFixtures;

/**
 * `jsonapi.storage_timezone` set to UTC overrides PHP's default timezone, here New York,
 * for the values the Eloquent data layer binds and writes. Rows are seeded as raw UTC
 * strings and the assertions read the column itself.
 */
#[CoversNothing]
final class EloquentConfiguredStorageTimezoneTest extends Orchestra
{
    use InteractsWithJsonApi;

    private string $previousTimezone;

    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, StorageTimezoneEloquentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.timezone', ShiftFixtures::ZONE);
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.storage_timezone', 'UTC');
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

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();

        parent::setUp();

        \date_default_timezone_set(ShiftFixtures::ZONE);
        DB::table('tz_shifts')->insert([
            ['id' => 1, 'starts_at' => '2026-10-01 16:00:00', 'day' => '2026-10-01'],
            ['id' => 2, 'starts_at' => '2026-10-01 14:00:00', 'day' => '2026-09-30'],
            ['id' => 3, 'starts_at' => '2026-10-02 13:00:00', 'day' => '2026-10-02'],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundIsComparedInTheConfiguredZone(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/api/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T11:00:00-04:00')));
        self::assertSame(['2'], $this->sortedIds('/api/shifts?filter[startsRange][max]=2026-10-01T15:00:00Z'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteIsStoredInTheConfiguredZone(): void
    {
        $created = $this->jsonApi()->withDocument(['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T12:00:00-04:00'],
        ]])->post('/api/shifts');
        $created->assertCreated();

        self::assertSame('2026-10-03 16:00:00', DB::table('tz_shifts')->where('id', $created->json('data.id'))->value('starts_at'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aConfiguredZoneIgnoresARuntimeChangeOfTheDefault(): void
    {
        \date_default_timezone_set('Europe/Paris');

        $created = $this->jsonApi()->withDocument(['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]])->post('/api/shifts');
        $created->assertCreated();

        self::assertSame('2026-10-03 16:00:00', DB::table('tz_shifts')->where('id', $created->json('data.id'))->value('starts_at'));
    }

    /**
     * @return list<string>
     */
    private function sortedIds(string $uri): array
    {
        $response = $this->jsonApi()->get($uri);
        $response->assertOk();

        $ids = [];
        $data = $response->json('data');
        self::assertIsArray($data);
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }
}
