<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\Fixtures;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The music-catalog workbench's own runtime behaviour, driven end-to-end over HTTP against the
 * reference Eloquent provider: the three `albums` actions (reissue / summary /
 * artwork), the `PlaylistApiPolicy` abilities (curate / deletePlaylist / inspectOwner) wired
 * through the Gate, and the playlist `beforeDelete` 409 guard. The dual-provider smoke suite
 * ({@see MusicCatalogSmokeTestCase}) proves both providers serve identical *reads*; this suite
 * pins the MusicCatalog-specific *behaviour* the docs (actions/authorization/errors pages) and
 * the parity audit cite as evidence — on the Eloquent arm (the `summary` action queries the
 * Eloquent model directly, so it is inherently Eloquent-backed).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentMusicCatalogBehaviorTest extends Orchestra
{
    use PinsCatalogClock;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
        ];
    }

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
        (new McCatalogSeeder())->run();
    }

    // --- the `reissue` album action (resource scope, Document input, ability-gated) --------

    public function test_a_write_capable_user_reissues_an_album_and_the_status_persists(): void
    {
        $this->actingAs($this->writer());

        $this->writeJsonApi('POST', '/api/albums/1/-actions/reissue', $this->reissueDocument('withdrawn'))
            ->assertOk()
            ->assertJsonPath('data.type', 'albums')
            ->assertJsonPath('data.id', '1')
            ->assertJsonPath('data.attributes.status', 'withdrawn');

        // Persisted through the albums persister — a follow-up read sees it.
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.status', 'withdrawn');
    }

    public function test_a_non_write_user_is_denied_the_reissue_action_with_a_403(): void
    {
        $this->actingAs($this->reader());

        // A valid document, so the 403 is the ability gate (validate-then-authorize), not a 422.
        $this->writeJsonApi('POST', '/api/albums/1/-actions/reissue', $this->reissueDocument('withdrawn'))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403')->assertJsonPath('errors.0.code', 'ACCESS_DENIED');

        // Denied before the handler ran: album 1 keeps its seeded status.
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.status', 'released');
    }

    // --- the `summary` album action (collection scope, meta-only) --------------------------

    public function test_the_summary_action_returns_the_release_lifecycle_meta(): void
    {
        // No ability declared → open. Both seeded albums are `released`.
        $this->writeJsonApi('POST', '/api/albums/-actions/summary', [])
            ->assertOk()
            ->assertJsonPath('meta.albums.released', 2)
            ->assertJsonPath('meta.albums.unreleased', 0)
            ->assertJsonPath('meta.albums.total', 2);
    }

    // --- the `artwork` album action (resource scope, Raw input, 204) -----------------------

    public function test_the_artwork_action_stores_the_raw_upload_and_returns_204(): void
    {
        $this->call('POST', '/api/albums/1/-actions/artwork', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => 'image/png',
        ], 'RAW-PNG-BYTES')->assertStatus(204);

        // The raw body was attached to the album and persisted (artwork is a read-only field).
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.artwork', 'RAW-PNG-BYTES');
    }

    public function test_the_artwork_action_refuses_a_type_it_does_not_accept(): void
    {
        $this->call('POST', '/api/albums/1/-actions/artwork', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => 'text/plain',
        ], 'not an image')->assertStatus(415);
    }

    // --- the `import` album action (collection scope, several accepted bodies) --------------

    public function test_the_import_action_reports_each_accepted_media_type(): void
    {
        foreach (['text/csv' => "title\nBlue\n", 'image/png' => 'PNG', 'application/json' => '[{"title":"Blue"}]'] as $type => $body) {
            $this->call('POST', '/api/albums/-actions/import', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => $type], $body)
                ->assertOk()
                ->assertJsonPath('meta.import.mediaType', $type)
                ->assertJsonPath('meta.import.bytes', \strlen($body));
        }

        $this->call('POST', '/api/albums/-actions/import', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => 'text/plain'], 'x')
            ->assertStatus(415);
    }

    // --- the `export` album action (collection scope, raw responses) -----------------------

    public function test_the_export_action_answers_in_the_negotiated_media_type(): void
    {
        $csv = $this->get('/api/albums/-actions/export', ['Accept' => 'text/csv'])->assertOk();
        self::assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        self::assertSame('attachment; filename="albums.csv"', $csv->headers->get('Content-Disposition'));
        self::assertStringStartsWith("id,title\n1,", (string) $csv->getContent());

        $json = $this->get('/api/albums/-actions/export', ['Accept' => 'application/json'])->assertOk();
        self::assertSame('{"published":2,"unpublished":0}', $json->getContent());

        $pdf = $this->get('/api/albums/-actions/export', ['Accept' => 'application/pdf'])->assertOk();
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        self::assertSame("%PDF-1.7\n% 2 albums\n%%EOF\n", $pdf->streamedContent());

        $this->get('/api/albums/-actions/export', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonPath('meta.albums.published', 2)
            ->assertJsonPath('meta.albums.unpublished', 0);
    }

    // --- the PlaylistApiPolicy abilities --------------------------------------

    public function test_the_owner_may_curate_the_playlist_and_the_retitle_refreshes_the_slug(): void
    {
        // The owner (id 1) passes `curate`; the beforeUpdate hook re-derives the slug.
        $this->actingAs($this->owner());

        $this->writeJsonApi('PATCH', '/api/playlists/' . Fixtures::PLAYLIST_ONE, $this->retitleDocument('Evening Mix'))
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'Evening Mix')
            ->assertJsonPath('data.attributes.slug', 'evening-mix');
    }

    public function test_a_non_owner_is_denied_the_curate_update(): void
    {
        $this->actingAs($this->stranger());

        $this->writeJsonApi('PATCH', '/api/playlists/' . Fixtures::PLAYLIST_ONE, $this->retitleDocument('Hijacked'))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403')->assertJsonPath('errors.0.code', 'ACCESS_DENIED');

        // Unchanged: the seeded title/slug survive the denied update.
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE)
            ->assertJsonPath('data.attributes.title', 'Morning Mix')
            ->assertJsonPath('data.attributes.slug', 'morning-mix');
    }

    public function test_an_admin_bypasses_curate_through_the_policy_before_hook(): void
    {
        $this->actingAs($this->admin());

        $this->writeJsonApi('PATCH', '/api/playlists/' . Fixtures::PLAYLIST_ONE, $this->retitleDocument('Admin Mix'))
            ->assertOk()
            ->assertJsonPath('data.attributes.slug', 'admin-mix');
    }

    public function test_delete_playlist_is_admin_only_and_denied_for_a_non_admin(): void
    {
        // deletePlaylist grants nobody but the before() admin bypass, so even the owner is 403.
        $this->actingAs($this->owner());

        $this->deleteJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE)
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403')->assertJsonPath('errors.0.code', 'ACCESS_DENIED');
    }

    public function test_deleting_a_non_empty_playlist_as_admin_is_a_409_from_the_before_delete_guard(): void
    {
        // Admin passes the delete gate (before() bypass); the beforeDelete hook then refuses a
        // playlist that still references tracks (PLAYLIST_ONE has a plain track) with a 409.
        $this->actingAs($this->admin());

        $this->deleteJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.status', '409');

        // The guard aborted the delete: the playlist is still there.
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE)->assertOk();
    }

    public function test_the_owner_relation_read_is_gated_by_inspect_owner_while_public_owner_stays_open(): void
    {
        // `owner` declares security(read: 'inspectOwner') — admin-only (before() bypass). The
        // relationship endpoint targets the admin-only `users` type, so on the default server
        // the linkage renders no `data` member; the gate outcome (200 vs 403) is what we pin.
        $this->actingAs($this->admin());
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/owner')
            ->assertOk();

        // A non-admin is denied the gated owner relation.
        $this->actingAs($this->stranger());
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/owner')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403')->assertJsonPath('errors.0.code', 'ACCESS_DENIED');

        // A guest is likewise denied (inspectOwner declines a null user).
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/owner')
            ->assertStatus(403);

        // `publicOwner` (the curated second view of the same column, targeting the
        // default-server `public-profiles` type) carries no read gate, so even a guest reads it.
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/public-owner')
            ->assertOk()
            ->assertJsonPath('data.type', 'public-profiles');
    }

    /**
     * A valid `albums` document carrying the create-required fields (title/releasedAt) plus the
     * target status — the reissue handler only reads `status`, but the action's Document input
     * runs the create-context validation bridge, so the required fields must be present.
     *
     * @return array<string, mixed>
     */
    private function reissueDocument(string $status): array
    {
        return [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'OK Computer',
                    'releasedAt' => '1997-05-21T00:00:00+00:00',
                    'status' => $status,
                ],
            ],
        ];
    }

    // --- guaranteed() read attributes carry a value on a fresh create ----------------------

    public function test_a_created_album_and_track_render_their_column_defaults(): void
    {
        $this->actingAs($this->admin());

        $album = $this->writeJsonApi('POST', '/api/albums', ['data' => [
            'type' => 'albums',
            'attributes' => ['title' => 'Fresh', 'releasedAt' => '2020-01-01T00:00:00+00:00'],
        ]])->assertCreated();
        $album->assertJsonPath('data.attributes.explicit', false);
        $album->assertJsonPath('data.attributes.status', 'released');

        $track = $this->writeJsonApi('POST', '/api/tracks', ['data' => [
            'type' => 'tracks',
            'attributes' => ['title' => 'Fresh', 'trackNumber' => 7, 'genres' => ['shoegaze']],
        ]])->assertCreated();
        $track->assertJsonPath('data.attributes.trackNumber', 7);
        $track->assertJsonPath('data.attributes.durationSeconds', 0);
        $track->assertJsonPath('data.attributes.explicit', false);
    }

    public function test_a_track_created_without_a_number_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->writeJsonApi('POST', '/api/tracks', ['data' => [
            'type' => 'tracks',
            'attributes' => ['title' => 'Fresh', 'genres' => ['shoegaze']],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/trackNumber');
    }

    public function test_a_track_created_without_genres_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->writeJsonApi('POST', '/api/tracks', ['data' => [
            'type' => 'tracks',
            'attributes' => ['title' => 'Fresh', 'trackNumber' => 7],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/genres');
    }

    public function test_a_created_playlist_renders_its_public_default_and_a_new_pivot_member_its_added_at(): void
    {
        $this->actingAs($this->owner());

        $playlist = $this->writeJsonApi('POST', '/api/playlists', ['data' => [
            'type' => 'playlists',
            'attributes' => ['title' => 'Fresh Mix'],
        ]])->assertCreated();
        $playlist->assertJsonPath('data.attributes.public', true);
        $playlist->assertJsonPath('data.attributes.slug', 'fresh-mix');

        // A member attached over the API leaves `addedAt` out (it is read-only), so the
        // database stamps it.
        $this->actingAs($this->admin());
        $this->writeJsonApi('POST', '/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/orderedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 3]]],
        ]])->assertSuccessful();

        $members = $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/orderedTracks?page[size]=10')
            ->assertOk()
            ->json('data');
        self::assertIsArray($members);
        $added = \array_values(\array_filter($members, static fn(mixed $member): bool => \is_array($member) && ($member['id'] ?? null) === '3'));
        self::assertCount(1, $added);
        self::assertIsArray($added[0]);
        self::assertIsString(\data_get($added[0], 'meta.pivot.addedAt'));
    }

    /**
     * @return array<string, mixed>
     */
    private function retitleDocument(string $title): array
    {
        return [
            'data' => [
                'type' => 'playlists',
                'id' => Fixtures::PLAYLIST_ONE,
                'attributes' => ['title' => $title],
            ],
        ];
    }

    private function owner(): User
    {
        // id 1 matches the seeded playlist owner_id, so `curate` authorizes the ownership.
        return new User(['id' => 1, 'name' => 'Ada', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    private function stranger(): User
    {
        return new User(['id' => 2, 'name' => 'Grace', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    private function admin(): User
    {
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'can_read' => false, 'is_admin' => true]);
    }

    private function writer(): User
    {
        return new User(['id' => 4, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    private function reader(): User
    {
        return new User(['id' => 5, 'name' => 'Reader', 'can_write' => false, 'can_read' => true, 'is_admin' => false]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deleteJsonApi(string $uri): TestResponse
    {
        return $this->call('DELETE', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => self::MEDIA_TYPE,
        ]));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
