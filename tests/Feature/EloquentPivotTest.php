<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see PivotWriteTestCase} against the **reference Eloquent provider/persister pair** over
 * real SQL (in-memory SQLite), plus the Eloquent-only pivot proofs: the stored
 * `position`/`weight`/`addedAt` render as each member's `meta.pivot` on the related endpoint,
 * the relationship-linkage endpoint and the mutation echo (ADR 0008); and the
 * merge-before-validate pass lets a partial update of an existing member reorder it without
 * re-sending the required `position` (the stored value is merged in).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotTest extends PivotWriteTestCase
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    public function test_meta_pivot_renders_on_the_related_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '1');

        self::assertNotNull($pivot);
        self::assertSame(1, $pivot['position'] ?? null);
        self::assertSame(1, $pivot['weight'] ?? null);
        self::assertSame('2024-01-01 00:00:00', $pivot['addedAt'] ?? null);
    }

    public function test_meta_pivot_renders_on_the_relationship_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/relationships/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '2');

        self::assertNotNull($pivot);
        self::assertSame(2, $pivot['position'] ?? null);
        self::assertSame(2, $pivot['weight'] ?? null);
    }

    public function test_the_mutation_echo_carries_meta_pivot(): void
    {
        // A pivot mutation's 200 linkage echo carries the just-written pivot values.
        $response = $this->writeMany('PATCH', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'weight' => 6]]],
        ]);

        $response->assertOk();
        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '2');

        self::assertNotNull($pivot);
        self::assertSame(5, $pivot['position'] ?? null);
        self::assertSame(6, $pivot['weight'] ?? null);
    }

    public function test_a_partial_pivot_update_of_an_existing_member_preserves_the_stored_position(): void
    {
        // Track 1 is already in playlist 1 at position 1; a Replace carrying ONLY its weight
        // reorders it in place — the stored position (1) is merged in, so the required-position
        // rule does not fire, and the weight is updated.
        $response = $this->writeMany('PATCH', '/api/playlists/1/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['weight' => 99]]],
        ]);
        $response->assertOk();

        $read = $this->get('/api/playlists/1/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        /** @var list<array<string, mixed>> $data */
        $data = $read->json('data');
        $pivot = $this->pivotOf($data, '1');

        self::assertNotNull($pivot);
        self::assertSame(1, $pivot['position'] ?? null);
        self::assertSame(99, $pivot['weight'] ?? null);
    }

    /**
     * The `meta.pivot` map of the member with the given id in a related/linkage document, or
     * null when the member is absent / carries no pivot meta.
     *
     * @param list<array<string, mixed>> $data
     *
     * @return array<array-key, mixed>|null
     */
    private function pivotOf(array $data, string $id): ?array
    {
        foreach ($data as $member) {
            if (($member['id'] ?? null) !== $id) {
                continue;
            }
            $meta = $member['meta'] ?? null;
            $pivot = \is_array($meta) ? ($meta['pivot'] ?? null) : null;

            return \is_array($pivot) ? $pivot : null;
        }

        return null;
    }
}
