<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Support\AuditLog;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * The cross-cutting event-listener suite (backs the listener passage in `lifecycle.md`;
 * parity finding F2): the workbench's `AuditLogSubscriber` — the Laravel port of the
 * Symfony example's subscriber — exercised end to end over HTTP on BOTH provider arms.
 *
 *  - an **audit record** is appended on every committed write ({@see \haddowg\JsonApiLaravel\Event\AfterSaveEvent}
 *    fires for create AND update, {@see \haddowg\JsonApiLaravel\Event\AfterDeleteEvent}
 *    for a delete) — and a *failed* write (the playlist `beforeDelete` `409` guard, a
 *    denied ability) appends **nothing**, proving the after events are post-commit;
 *  - a **`serving` gate** freezes every write with a `403` when the `X-Read-Only: on`
 *    header is set, while reads pass — one deploy flag spanning the whole API.
 *
 * The trail is read back through the singleton {@see AuditLog} store.
 *
 * @internal
 */
#[CoversNothing]
abstract class AuditListenerTestCase extends Orchestra
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

    public function test_every_committed_write_appends_one_audit_entry_and_reads_append_none(): void
    {
        // Create (no ability gate on playlist create). A real UUID externalId is supplied
        // so the beforeCreate hook does not stamp its non-UUID `ext-…` placeholder, which
        // the Uuid field rule would reject on the follow-up update.
        $created = $this->write('POST', '/api/playlists', [
            'data' => ['type' => 'playlists', 'attributes' => [
                'title' => 'Audited',
                'public' => true,
                'externalId' => '3f8a4e1e-2b7d-4b1e-9c69-0a4a2f0e5b10',
            ]],
        ]);
        $created->assertStatus(201);
        $id = $created->json('data.id');
        self::assertIsString($id);

        // Update + delete as admin (curate/deletePlaylist grant via the policy before() bypass).
        $this->actingAs($this->admin());
        $this->write('PATCH', '/api/playlists/' . $id, [
            'data' => ['type' => 'playlists', 'id' => $id, 'attributes' => ['title' => 'Audited (Renamed)']],
        ])->assertOk();
        $this->delete('/api/playlists/' . $id, [], ['Accept' => self::MEDIA_TYPE])->assertStatus(204);

        // A read appends nothing.
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE)->assertOk();

        self::assertSame(
            [
                'created playlists#' . $id,
                'updated playlists#' . $id,
                'deleted playlists#' . $id,
            ],
            $this->audit()->entries(),
        );
    }

    public function test_a_failed_write_appends_no_audit_entry(): void
    {
        // The seeded playlist still references tracks, so the beforeDelete hook guard
        // refuses with a 409 (admin passes the deletePlaylist gate; the guard aborts
        // BEFORE the persister runs) — and the after-commit listener never records it.
        $this->actingAs($this->admin());
        $this->delete('/api/playlists/' . Fixtures::PLAYLIST_ONE, [], ['Accept' => self::MEDIA_TYPE])
            ->assertStatus(409);

        // A denied ability likewise aborts pre-commit: no entry.
        $this->actingAs($this->stranger());
        $this->write('PATCH', '/api/playlists/' . Fixtures::PLAYLIST_ONE, [
            'data' => ['type' => 'playlists', 'id' => Fixtures::PLAYLIST_ONE, 'attributes' => ['title' => 'Hijacked']],
        ])->assertStatus(403);

        // Both writes failed, the playlist survives, the trail is empty.
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE)->assertOk();
        self::assertSame([], $this->audit()->entries());
    }

    public function test_the_serving_gate_freezes_writes_when_the_read_only_header_is_set(): void
    {
        // The serving listener fires once per request before the operation: with the
        // read-only header set a write aborts with a 403 and never commits (no audit).
        $this->write('POST', '/api/playlists', [
            'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Blocked', 'public' => true]],
        ], ['X-Read-Only' => 'on'])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
        self::assertSame([], $this->audit()->entries());

        // A read is unaffected by the gate (it only blocks mutating methods).
        $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE, ['X-Read-Only' => 'on'])->assertOk();
    }

    private function audit(): AuditLog
    {
        $audit = $this->app?->make(AuditLog::class);
        \assert($audit instanceof AuditLog);

        return $audit;
    }

    private function admin(): User
    {
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'can_read' => false, 'is_admin' => true]);
    }

    private function stranger(): User
    {
        return new User(['id' => 2, 'name' => 'Grace', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    /**
     * Issues a JSON:API GET with the correct Accept header.
     *
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE] + $headers);
    }

    /**
     * Issues a JSON:API write with the correct Content-Type + Accept headers.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body, array $headers = []): TestResponse
    {
        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => self::MEDIA_TYPE,
            'Content-Type' => self::MEDIA_TYPE,
        ] + $headers), (string) \json_encode($body));
    }
}
