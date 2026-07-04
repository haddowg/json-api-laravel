<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see PivotWriteTestCase} against the **in-memory witness** — the ground-truth half of the
 * dual-provider pivot-write contract, plus the boundary proof: the witness stores NO pivot
 * meta (a pivot column needs an association entity it cannot model), so a pivot write still
 * changes the membership but renders NO `meta.pivot` on the related or relationship endpoints
 * — `meta.pivot` is Eloquent-only (ADR 0008, blueprint §3d). The data lives in the provider
 * registration, so no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPivotTest extends PivotWriteTestCase
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
            ConformanceInMemoryServiceProvider::class,
        ];
    }

    public function test_no_meta_pivot_renders_on_the_related_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        self::assertNotSame([], $data);
        foreach ($data as $member) {
            $meta = $member['meta'] ?? null;
            self::assertTrue(!\is_array($meta) || !\array_key_exists('pivot', $meta));
        }
    }

    public function test_no_meta_pivot_renders_on_the_relationship_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/relationships/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        self::assertNotSame([], $data);
        foreach ($data as $identifier) {
            $meta = $identifier['meta'] ?? null;
            self::assertTrue(!\is_array($meta) || !\array_key_exists('pivot', $meta));
        }
    }
}
