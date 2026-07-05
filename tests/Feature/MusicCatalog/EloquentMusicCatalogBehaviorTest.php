<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\Fixtures;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The music-catalog workbench's own runtime behaviour, driven end-to-end over HTTP against the
 * reference Eloquent provider (decision 14): the three `albums` actions (reissue / summary /
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
            ->assertJsonPath('errors.0.status', '403');

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

    // --- the PlaylistApiPolicy abilities (decision 7) --------------------------------------

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
            ->assertJsonPath('errors.0.status', '403');

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
            ->assertJsonPath('errors.0.status', '403');
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
            ->assertJsonPath('errors.0.status', '403');

        // A guest is likewise denied (inspectOwner declines a null user).
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/owner')
            ->assertStatus(403);

        // `publicOwner` (the curated second view of the same column, targeting the
        // default-server `public-profiles` type) carries no read gate, so even a guest reads it.
        $this->readJsonApi('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/publicOwner')
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
