<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ComputedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot\ComputedPivotEloquentServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see ComputedPivotTestCase} on the Eloquent provider, plus a relation mixing stored pivot
 * columns with a computed field, one of the stored ones read through an extract hook. Crate
 * 1's `mixedTracks` rows carry the seeded positions and weights.
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

            self::assertSame(['position' => 2, 'label' => 'Best Of / Karma Police', 'weight' => 300], $this->pivotOf($response, '3'), $uri);
        }

        $this->assertResponseMatchesGeneratedSchema(
            $this->read('/api/crates/1/relationships/mixedTracks')->assertOk(),
            'crates',
            SchemaDocumentKind::Relationship,
            'mixedTracks',
        );
    }

    public function test_a_stored_pivot_field_still_sorts_beside_a_computed_one(): void
    {
        $response = $this->read('/api/crates/1/mixedTracks?sort=-position')->assertOk();

        self::assertSame('4', $response->json('data.0.id'));
    }

    public function test_a_mixed_write_stores_the_stored_field_and_ignores_the_computed_one(): void
    {
        $response = $this->send('POST', '/api/crates/2/relationships/mixedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'label' => 'mine']]],
        ]]);

        $response->assertOk();
        self::assertSame(['position' => 7, 'label' => 'Solo / Everything In Its Right Place', 'weight' => null], $this->pivotOf($response, '4'));
        self::assertEquals(7, DB::table('playlist_track')->where('playlist_id', 2)->where('track_id', 4)->value('position'));
    }

    public function test_a_stored_field_with_an_extract_hook_is_written_on_a_relationship_replace(): void
    {
        // Track 3 is attached, so it is an update; track 5 is new, so it is a create.
        $response = $this->send('PATCH', '/api/crates/1/relationships/mixedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['weight' => 9, 'label' => 'mine']]],
            ['type' => 'tracks', 'id' => '5', 'meta' => ['pivot' => ['position' => 6, 'weight' => 7]]],
        ]])->assertOk();

        // The column holds the written value; only the read goes through the hook.
        self::assertSame(['position' => 2, 'label' => 'Best Of / Karma Police', 'weight' => 900], $this->pivotOf($response, '3'));
        self::assertSame(['position' => 6, 'label' => 'Best Of / Idioteque', 'weight' => 700], $this->pivotOf($response, '5'));
        self::assertEquals(9, DB::table('playlist_track')->where('playlist_id', 1)->where('track_id', 3)->value('weight'));
        self::assertEquals(7, DB::table('playlist_track')->where('playlist_id', 1)->where('track_id', 5)->value('weight'));
    }

    public function test_a_stored_field_with_an_extract_hook_is_written_on_create_and_update(): void
    {
        $created = $this->send('POST', '/api/crates', ['data' => [
            'type' => 'crates',
            'attributes' => ['title' => 'Fresh'],
            'relationships' => ['mixedTracks' => ['data' => [
                ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 3, 'weight' => 4, 'label' => 'mine']]],
            ]]],
        ]])->assertCreated();
        $id = $created->json('data.id');
        self::assertIsString($id);
        self::assertEquals(4, DB::table('playlist_track')->where('playlist_id', $id)->where('track_id', 2)->value('weight'));

        $read = $this->read('/api/crates/' . $id . '/relationships/mixedTracks')->assertOk();
        self::assertSame(['position' => 3, 'label' => 'Fresh / Paranoid Android', 'weight' => 400], $this->pivotOf($read, '2'));

        $this->send('PATCH', '/api/crates/' . $id, ['data' => [
            'type' => 'crates',
            'id' => $id,
            'relationships' => ['mixedTracks' => ['data' => [
                ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['weight' => 8]]],
            ]]],
        ]])->assertOk();

        self::assertEquals(8, DB::table('playlist_track')->where('playlist_id', $id)->where('track_id', 2)->value('weight'));
        $updated = $this->read('/api/crates/' . $id . '/relationships/mixedTracks')->assertOk();
        self::assertSame(['position' => 3, 'label' => 'Fresh / Paranoid Android', 'weight' => 800], $this->pivotOf($updated, '2'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, array $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode($body));
    }
}
