<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see PivotWriteTestCase} against the **in-memory witness**, the ground-truth half of the
 * dual-provider pivot-write contract. The witness keeps each pivot row beside its parent, so a
 * pivot write stores the fields it sends and every read renders them as `meta.pivot`. The
 * data lives in the provider registration, so no database is touched.
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

    public function test_stored_pivot_fields_render_on_the_related_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        self::assertSame(['position' => 1, 'weight' => 1, 'addedAt' => '2024-01-01T00:00:00+00:00'], $response->json('data.0.meta.pivot'));
    }

    public function test_stored_pivot_fields_render_on_the_relationship_endpoint(): void
    {
        $response = $this->get('/api/playlists/1/relationships/orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        self::assertSame(['position' => 2, 'weight' => 2, 'addedAt' => '2024-01-02T00:00:00+00:00'], $response->json('data.1.meta.pivot'));
    }

    public function test_a_write_stores_the_pivot_fields_it_sends_and_a_read_renders_them(): void
    {
        $add = $this->call('POST', '/api/playlists/3/relationships/orderedTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 3, 'weight' => 4]]]]]));
        $add->assertOk();

        $read = $this->get('/api/playlists/3?include=orderedTracks', ['Accept' => self::MEDIA_TYPE]);
        $read->assertOk();
        self::assertSame(['position' => 3, 'weight' => 4, 'addedAt' => null], $read->json('data.relationships.orderedTracks.data.0.meta.pivot'));

        $update = $this->call('PATCH', '/api/playlists/3/relationships/orderedTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['weight' => 9]]]]]));
        $update->assertOk();
        self::assertSame(['position' => 3, 'weight' => 9, 'addedAt' => null], $update->json('data.0.meta.pivot'));

        $remove = $this->call('DELETE', '/api/playlists/3/relationships/orderedTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [['type' => 'tracks', 'id' => '2']]]));
        $remove->assertOk();
        self::assertSame([], $remove->json('data'));
    }
}
