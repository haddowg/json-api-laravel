<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\TimezoneEloquentServiceProvider;
use Workbench\App\Timezone\Models\Gig;
use Workbench\App\Timezone\Models\Performer;
use Workbench\App\Timezone\Models\Venue;
use Workbench\App\Timezone\TimezoneFixtures;

/**
 * {@see OutputTimezoneConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite, plus the pivot meta only Eloquent renders.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentOutputTimezoneConformanceTest extends OutputTimezoneConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TimezoneEloquentServiceProvider::class;
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
        foreach (TimezoneFixtures::venues() as $row) {
            Venue::query()->create($row);
        }
        foreach (TimezoneFixtures::performers() as $row) {
            Performer::query()->create($row);
        }
        foreach (TimezoneFixtures::gigs() as $row) {
            Gig::query()->create($row);
        }
        foreach (TimezoneFixtures::bookings() as $row) {
            $gig = Gig::query()->findOrFail($row['gig_id']);
            $gig->performers()->attach($row['performer_id'], ['booked_at' => $row['booked_at']]);
        }
    }

    #[Test]
    #[Group('output-timezone')]
    public function pivotMetaRendersInTheServerZone(): void
    {
        $this->jsonApi()->get('/api/gigs/1/performers')
            ->assertOk()
            ->assertJsonPath('data.0.meta.pivot.bookedAt', '2024-05-01T08:00:00+00:00');

        $this->jsonApi()->get('/api/gigs/1/relationships/performers')
            ->assertOk()
            ->assertJsonPath('data.0.meta.pivot.bookedAt', '2024-05-01T08:00:00+00:00');

        $this->jsonApi()->get('/admin/gigs/1/relationships/performers')
            ->assertOk()
            ->assertJsonPath('data.0.meta.pivot.bookedAt', '2024-05-01T04:00:00-04:00');
    }

    #[Test]
    #[Group('output-timezone')]
    #[DefineEnvironment('withoutOutputTimezone')]
    public function pivotMetaIsUnchangedWithoutAnOutputTimezone(): void
    {
        $expected = \json_decode((string) \json_encode(TimezoneFixtures::instant('2024-05-01 08:00:00')), true);

        $this->jsonApi()->get('/api/gigs/1/relationships/performers')
            ->assertOk()
            ->assertJsonPath('data.0.meta.pivot.bookedAt', $expected);
    }
}
