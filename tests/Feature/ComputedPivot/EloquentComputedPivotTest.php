<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ComputedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot\ComputedPivotEloquentServiceProvider;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see ComputedPivotTestCase} on the Eloquent provider, plus a relation mixing a stored
 * pivot column with a computed field.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentComputedPivotTest extends ComputedPivotTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentWorkbenchServiceProvider::class,
            ComputedPivotEloquentServiceProvider::class,
        ];
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    public function test_a_stored_and_a_computed_pivot_field_render_side_by_side(): void
    {
        foreach (['/api/crates/1/mixedTracks', '/api/crates/1/relationships/mixedTracks'] as $uri) {
            $response = $this->read($uri)->assertOk();

            self::assertSame(['position' => 2, 'label' => 'Best Of / Karma Police'], $this->pivotOf($response, '3'), $uri);
        }
    }

    public function test_a_stored_pivot_field_still_sorts_beside_a_computed_one(): void
    {
        $response = $this->read('/api/crates/1/mixedTracks?sort=-position')->assertOk();

        self::assertSame('4', $response->json('data.0.id'));
    }

    public function test_a_mixed_write_stores_the_stored_field_and_ignores_the_computed_one(): void
    {
        $response = $this->call('POST', '/api/crates/2/relationships/mixedTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'label' => 'mine']]],
        ]]));

        $response->assertOk();
        self::assertSame(['position' => 7, 'label' => 'Solo / Everything In Its Right Place'], $this->pivotOf($response, '4'));
        self::assertEquals(7, DB::table('playlist_track')->where('playlist_id', 2)->where('track_id', 4)->value('position'));
    }
}
