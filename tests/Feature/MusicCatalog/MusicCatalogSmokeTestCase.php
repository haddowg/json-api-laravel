<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
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
    use SchemaConformanceTrait;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private string $docsCacheDir = '';

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

    /**
     * Serves the OpenAPI document from a cache directory of this test's own, so it never
     * reads artifacts another test left behind.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function exposeDocs($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $this->docsCacheDir = \sys_get_temp_dir() . '/jsonapi-music-catalog-smoke-' . \uniqid();
        $config->set('app.debug', true);
        $config->set('jsonapi.openapi.cache_path', $this->docsCacheDir);
    }

    protected function tearDown(): void
    {
        if ($this->docsCacheDir !== '') {
            $this->app?->make(ArtifactStore::class)->clear();
            @\rmdir($this->docsCacheDir);
        }

        parent::tearDown();
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

    public function test_admin_users_responses_conform_to_the_generated_schema(): void
    {
        $index = $this->fetch('/admin/users')->assertOk();
        $this->assertResponseMatchesGeneratedSchema($index, 'users', SchemaDocumentKind::Collection, server: 'admin');

        $ids = \array_column((array) $index->json('data'), 'id');
        self::assertCount(\count(Fixtures::users()), $ids);
        foreach ($ids as $id) {
            self::assertIsString($id);
            $show = $this->fetch('/admin/users/' . $id)->assertOk();
            $this->assertResponseMatchesGeneratedSchema($show, 'users', SchemaDocumentKind::Single, server: 'admin');
        }
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

    #[DefineEnvironment('exposeDocs')]
    public function test_the_renamed_relation_is_served_and_linked_by_its_uri_field_name(): void
    {
        // `publicOwner` declares `withUriFieldName('public-owner')`: the member keeps the
        // relation name, its links name the URI segment.
        $show = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE)->assertOk();
        $show->assertJsonPath('data.relationships.publicOwner.links.self.href', 'http://localhost/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/public-owner');
        $show->assertJsonPath('data.relationships.publicOwner.links.related.href', 'http://localhost/api/playlists/' . Fixtures::PLAYLIST_ONE . '/public-owner');
        $show->assertJsonPath('data.relationships.publicOwner.links.related.describedby', '/docs.json#/paths/~1playlists~1%7Bid%7D~1public-owner/get');

        // Both endpoints answer under the new segment.
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/public-owner')
            ->assertOk()
            ->assertJsonPath('data', ['type' => 'public-profiles', 'id' => '1']);
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/public-owner')
            ->assertOk()
            ->assertJsonPath('data.type', 'public-profiles')
            ->assertJsonPath('data.id', '1');

        // A client written against the relation name still reaches both endpoints.
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/publicOwner')
            ->assertOk()
            ->assertJsonPath('data', ['type' => 'public-profiles', 'id' => '1']);
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/publicOwner')
            ->assertOk()
            ->assertJsonPath('data.type', 'public-profiles')
            ->assertJsonPath('data.id', '1');

        // The OpenAPI document keys the paths by the URI segment only.
        $paths = $this->get('/docs.json')->assertOk()->json('paths');
        self::assertIsArray($paths);
        self::assertArrayHasKey('/playlists/{id}/public-owner', $paths);
        self::assertArrayHasKey('/playlists/{id}/relationships/public-owner', $paths);
        self::assertArrayNotHasKey('/playlists/{id}/publicOwner', $paths);
        self::assertArrayNotHasKey('/playlists/{id}/relationships/publicOwner', $paths);
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

    public function test_playlist_ordered_tracks_relationship_endpoint_pages_by_default(): void
    {
        // The pivot relation's own paginator (two per page) applies to its linkage endpoint
        // on both arms, with or without a page request.
        $plain = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/orderedTracks')->assertOk();
        self::assertSame(['1', '2'], \array_column((array) $plain->json('data'), 'id'));
        $this->assertResponseMatchesGeneratedSchema($plain, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
        $first = $plain->json('links.first.href');
        self::assertIsString($first);
        self::assertStringEndsWith('?page%5Bnumber%5D=1&page%5Bsize%5D=2', $first);
        self::assertNull($plain->json('links.next'));

        $sized = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/orderedTracks?page[size]=1')->assertOk();
        self::assertSame(['1'], \array_column((array) $sized->json('data'), 'id'));
        $next = $sized->json('links.next.href');
        self::assertIsString($next);
        self::assertStringEndsWith('?page%5Bnumber%5D=2&page%5Bsize%5D=1', $next);
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

    public function test_catalog_exports_index_lists_the_seeded_exports(): void
    {
        $index = $this->fetch('/api/catalog-exports')->assertOk();
        $index->assertJsonPath('data.0.type', 'catalog-exports');
    }

    public function test_export_job_fetch_redirects_when_complete_and_reports_status_while_running(): void
    {
        // JSON:API asynchronous-processing completion: a completed job → 303 See Other to the
        // produced export (the ResolvesCompletionRedirect fetch-one seam), a running job → 200.
        $done = $this->fetch('/api/export-jobs/job-completed');
        $done->assertStatus(303);
        self::assertNotNull($done->headers->get('Location'));

        $running = $this->fetch('/api/export-jobs/job-processing')->assertOk();
        $running->assertJsonPath('data.type', 'export-jobs');
        $running->assertJsonPath('data.attributes.state', 'processing');
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

    public function test_catalog_export_create_is_accepted_for_async_processing(): void
    {
        // The always-async create: POST → 202 Accepted with a pollable export-jobs job document
        // (Content-Location + Retry-After), never a 201 (create: [new Accepted('export-jobs')]).
        $response = $this->write('POST', '/api/catalog-exports', [
            'data' => ['type' => 'catalog-exports', 'attributes' => ['format' => 'json']],
        ]);
        $response->assertStatus(202);
        self::assertNotNull($response->headers->get('Content-Location'));
        self::assertNotNull($response->headers->get('Retry-After'));
        $response->assertJsonPath('data.type', 'export-jobs');
    }

    // --- the download action ----------------------------------------------------

    public function test_album_download_answers_a_json_api_client_with_the_location_document(): void
    {
        $response = $this->fetch('/api/albums/1/-actions/download')->assertOk();

        $response->assertHeaderMissing('Location');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('Accept', $response->headers->get('Vary'));
        $response->assertExactJson([
            'jsonapi' => ['version' => '1.1'],
            'links' => [
                'self' => ['href' => 'http://localhost/api/albums/1/-actions/download'],
                'location' => [
                    'href' => 'https://storage.example.com/albums/1.csv?signature=example',
                    'type' => 'text/csv',
                    'meta' => ['expiresAt' => '2026-01-01T11:05:00+00:00'],
                ],
            ],
            'meta' => ['expiresAt' => '2026-01-01T11:05:00+00:00'],
        ]);
    }

    public function test_album_download_redirects_a_browser(): void
    {
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        foreach ([$accept, 'text/csv', '*/*', ''] as $header) {
            $response = $this->get('/api/albums/1/-actions/download', ['Accept' => $header]);

            $response->assertStatus(303);
            $response->assertHeader('Location', 'https://storage.example.com/albums/1.csv?signature=example');
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertSame('Accept', $response->headers->get('Vary'));
        }
    }

    public function test_album_download_is_an_album_link(): void
    {
        $this->fetch('/api/albums/1')->assertOk()
            ->assertJsonPath('data.links.download.href', 'http://localhost/api/albums/1/-actions/download');
    }

    public function test_album_download_errors_stay_json_api(): void
    {
        $this->get('/api/albums/999999/-actions/download', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertJsonPath('errors.0.status', '404');
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
