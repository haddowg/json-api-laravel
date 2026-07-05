<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * The dual-provider smoke suite for the unified music-catalog domain (decision 14): one set
 * of index + show (+ representative write) assertions run against BOTH the Eloquent and the
 * in-memory concretes, proving every type is served identically by both providers — the
 * parity premise. It exercises the id-strategy matrix (auto-increment, client natural key,
 * ULID, encoded), the multi-server witness (`albums` on default + admin, `users` admin-only),
 * declarative headers (cache on `genres`, deprecation on `devices`), the self-link opt-out,
 * and the polymorphic reads (`favorites.favoritable`, `libraries.items`).
 *
 * @internal
 */
#[CoversNothing]
abstract class MusicCatalogSmokeTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
    }

    // --- reads -----------------------------------------------------------------

    public function test_artists_index_and_show(): void
    {
        $this->fetch('/api/artists')->assertOk()->assertJsonPath('data.0.type', 'artists');
        $show = $this->fetch('/api/artists/1')->assertOk();
        $show->assertJsonPath('data.type', 'artists');
        $show->assertJsonPath('data.attributes.name', 'Radiohead');
        // The computed read-only trackCount attribute.
        $show->assertJsonPath('data.attributes.trackCount', 3);
    }

    public function test_albums_show_on_both_servers_with_enum_map_and_default_include(): void
    {
        $default = $this->fetch('/api/albums/1')->assertOk();
        $default->assertJsonPath('data.attributes.status', 'released');
        $default->assertJsonPath('data.attributes.releaseInfo.label', 'Parlophone');
        // Default include: the artist rides in `included` with no ?include.
        $default->assertJsonPath('included.0.type', 'artists');

        // The multi-server witness: the same album on the admin server.
        $this->fetch('/admin/albums/1')->assertOk()->assertJsonPath('data.type', 'albums');
    }

    public function test_tracks_index_and_show_with_arraylist_and_computed(): void
    {
        $show = $this->fetch('/api/tracks/1')->assertOk();
        $show->assertJsonPath('data.attributes.durationSeconds', 284);
        $show->assertJsonPath('data.attributes.genres.0', 'alt-rock');
        $show->assertJsonPath('data.attributes.displayTitle', '1. Airbag');
    }

    public function test_genres_show_carries_cache_headers(): void
    {
        $show = $this->fetch('/api/genres/trip-hop')->assertOk();
        $show->assertJsonPath('data.id', 'trip-hop');
        self::assertStringContainsString('max-age=3600', (string) $show->headers->get('Cache-Control'));
    }

    public function test_devices_show_opts_out_of_self_link_and_is_deprecated(): void
    {
        $show = $this->fetch('/api/devices/' . Fixtures::DEVICE_ONE)->assertOk();
        $show->assertJsonPath('data.type', 'devices');
        // Self-link opt-out: no data.links.self.
        self::assertNull($show->json('data.links.self'));
        // RFC 8594 deprecation rides every response.
        self::assertNotNull($show->headers->get('Deprecation'));
        self::assertNotNull($show->headers->get('Sunset'));
    }

    public function test_products_index_renders_the_encoded_id(): void
    {
        // The wire id is the encoded token, never the integer storage key.
        $index = $this->fetch('/api/products')->assertOk();
        $id = $index->json('data.0.id');
        self::assertIsString($id);
        self::assertStringStartsWith('prod-', $id);
    }

    public function test_users_are_admin_only(): void
    {
        // Not exposed on the default server.
        $this->fetch('/api/users/1')->assertNotFound();
        // Resolved on the admin server; the write-only password never renders.
        $show = $this->fetch('/admin/users/1')->assertOk();
        $show->assertJsonPath('data.attributes.email', 'ada@music.example');
        self::assertNull($show->json('data.attributes.password'));
    }

    public function test_public_profiles_expose_only_the_display_name(): void
    {
        $show = $this->fetch('/api/public-profiles/1')->assertOk();
        $show->assertJsonPath('data.type', 'public-profiles');
        $show->assertJsonPath('data.attributes.displayName', 'Ada');
        // The private user columns are never declared on this view.
        self::assertNull($show->json('data.attributes.email'));
    }

    public function test_favorites_polymorphic_to_one_resolves_the_members_type(): void
    {
        // favorite 1 → a track; favorite 2 → an album (the morphTo resolves per-member type).
        $this->fetch('/api/favorites/1/favoritable')->assertOk()->assertJsonPath('data.type', 'tracks');
        $this->fetch('/api/favorites/2/favoritable')->assertOk()->assertJsonPath('data.type', 'albums');
    }

    public function test_libraries_polymorphic_to_many_returns_a_mixed_collection(): void
    {
        // The over-parity headline: a mixed track + album + artist collection off ONE relation.
        $items = $this->fetch('/api/libraries/1/items')->assertOk();
        $types = \array_column((array) $items->json('data'), 'type');
        \sort($types);
        self::assertSame(['albums', 'artists', 'tracks'], $types);
    }

    public function test_playlists_show(): void
    {
        $show = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE)->assertOk();
        $show->assertJsonPath('data.type', 'playlists');
        $show->assertJsonPath('data.attributes.slug', 'morning-mix');
    }

    public function test_playlist_ordered_tracks_serve_the_seeded_pivot_members(): void
    {
        // The pivot-bearing orderedTracks relation serves its seeded members on BOTH arms:
        // the Eloquent arm from the mc_playlist_track pivot, the in-memory arm from
        // Playlist::$entries — the unified domain's pivot read path exercised with real data.
        $related = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/orderedTracks')->assertOk();
        $ids = \array_column((array) $related->json('data'), 'id');
        \sort($ids);
        self::assertSame(['1', '2'], $ids);
    }

    public function test_charts_are_served_by_a_standalone_serializer(): void
    {
        // A resource-less type (a standalone serializer + a custom provider, no Resource and
        // no model) served identically by both provider arms — the capability-composition
        // witness (decision 3, bundle ADR 0024). Index + show over the two opened operations.
        $index = $this->fetch('/api/charts')->assertOk();
        $index->assertJsonPath('data.0.type', 'charts');

        $show = $this->fetch('/api/charts/1')->assertOk();
        $show->assertJsonPath('data.type', 'charts');
        $show->assertJsonPath('data.id', '1');
        $show->assertJsonPath('data.attributes.name', 'Weekly Top');
        $show->assertJsonPath('data.attributes.period', '2024-W03');
        $show->assertJsonPath('data.attributes.entries.0.rank', 1);
        $show->assertJsonPath('data.attributes.entries.0.trackId', '2');
    }

    public function test_countries_are_served_from_an_external_source(): void
    {
        // Reference data with no database behind it — the rows come from symfony/intl through
        // a standalone serializer + custom provider, read-only.
        $index = $this->fetch('/api/countries')->assertOk();
        $index->assertJsonPath('data.0.type', 'countries');

        $show = $this->fetch('/api/countries/GB')->assertOk();
        $show->assertJsonPath('data.type', 'countries');
        $show->assertJsonPath('data.id', 'GB');
        $show->assertJsonPath('data.attributes.name', 'United Kingdom');
    }

    public function test_standalone_types_are_read_only(): void
    {
        // The operation allow-list opened only the two fetch verbs, so a write verb is unrouted
        // (a resource-less serialize-plus-fetch type has no Create/Update/Delete).
        $this->write('POST', '/api/charts', [
            'data' => ['type' => 'charts', 'attributes' => ['name' => 'x']],
        ])->assertStatus(405);
    }

    // --- writes ----------------------------------------------------------------

    public function test_album_create_assigns_a_store_provided_id(): void
    {
        $response = $this->write('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Amnesiac', 'status' => 'released', 'releasedAt' => '2001-06-05T00:00:00+00:00'],
            ],
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('data.attributes.title', 'Amnesiac');
    }

    public function test_genre_create_requires_a_client_id(): void
    {
        // Client-generated natural key: a create WITH the id round-trips.
        $ok = $this->write('POST', '/api/genres', [
            'data' => ['type' => 'genres', 'id' => 'shoegaze', 'attributes' => ['name' => 'Shoegaze']],
        ]);
        $ok->assertStatus(201)->assertJsonPath('data.id', 'shoegaze');

        // A create WITHOUT the id is a 403 (ClientGeneratedIdRequired).
        $this->write('POST', '/api/genres', [
            'data' => ['type' => 'genres', 'attributes' => ['name' => 'Nu Metal']],
        ])->assertStatus(403);
    }

    public function test_playlist_create_derives_the_slug_via_the_hook(): void
    {
        $response = $this->write('POST', '/api/playlists', [
            'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Late Night', 'public' => true]],
        ]);
        $response->assertStatus(201);
        // The beforeCreate hook derived the read-only slug from the title.
        $response->assertJsonPath('data.attributes.slug', 'late-night');
    }

    /**
     * Issues a JSON:API GET with the correct Accept header.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Issues a JSON:API write with the correct Content-Type + Accept headers.
     *
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode($body));
    }
}
