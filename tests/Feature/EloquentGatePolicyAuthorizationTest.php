<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\Providers\GatePolicyServiceProvider;
use Workbench\Database\Seeders\MusicCatalogSeeder;

/**
 * The Eloquent-only authorization paths (PLAN decision 7) a POPO cannot exercise (it
 * carries no Gate policy): the secured `artists` type declares NO `policy:` attribute, so
 * the {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} resolves through the
 * application Gate.
 *
 *  - **Model-registered policy** (`Gate::policy(Artist::class, ArtistApiPolicy::class)`):
 *    `view`/`create`/`update`/`delete` resolve to `ArtistApiPolicy` automatically.
 *  - **Gate::define**: the resource renames its list ability to `browseArtists`, which the
 *    policy lacks, so the Gate falls through to the `Gate::define('browseArtists', …)`
 *    closure — proving a renamed ability is Gate-resolved.
 *
 * The `artists` type sits on the unguarded `default` server, so an unauthenticated request
 * is denied by the policy/gate itself — a `403`, the Laravel-idiomatic guest denial
 * (contrast the auth-guarded `secure` server's `401` in the conformance suite).
 *
 * @internal
 */
final class EloquentGatePolicyAuthorizationTest extends Orchestra
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
            GatePolicyServiceProvider::class,
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
        (new MusicCatalogSeeder())->run();
    }

    // --- Gate::define path: the renamed `browseArtists` list ability ---

    #[Test]
    #[Group('spec:authorization')]
    public function theRenamedListAbilityResolvesThroughGateDefine(): void
    {
        // ArtistApiPolicy has no `browseArtists` method, so the Gate falls through to the
        // `Gate::define('browseArtists', …)` closure: a write-capable user is allowed, a
        // non-write user and a guest are denied.
        $this->actingAs($this->writer());
        $this->readJsonApi('/api/artists')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($this->reader());
        $this->readJsonApi('/api/artists')->assertStatus(403)->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function anUnauthenticatedListIsDeniedByTheDefinedAbility(): void
    {
        // No auth middleware on this server, so the guest reaches the gate and is denied
        // (the defined ability's non-nullable user parameter forbids guests) — a 403, not
        // a 401 (the Laravel guest-denial semantics).
        $this->readJsonApi('/api/artists')->assertStatus(403)->assertJsonPath('errors.0.status', '403');
    }

    // --- model-registered policy path: view / create / update / delete ---

    #[Test]
    #[Group('spec:authorization')]
    public function readResolvesThroughTheModelRegisteredPolicy(): void
    {
        $this->actingAs($this->writer());
        $this->readJsonApi('/api/artists/1')->assertOk()->assertJsonPath('data.id', '1');

        $this->actingAs($this->reader());
        $this->readJsonApi('/api/artists/1')->assertStatus(403)->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function anUnauthenticatedReadIsDeniedByTheModelPolicyAfterTheModelLoads(): void
    {
        // The model loads (a miss would be a 404 first), then the policy denies the guest
        // — a 403 on an existing resource.
        $this->readJsonApi('/api/artists/1')->assertStatus(403)->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function createResolvesThroughTheModelRegisteredPolicy(): void
    {
        $this->actingAs($this->writer());
        $this->writeJsonApi('POST', '/api/artists', $this->artistDocument())
            ->assertStatus(201)
            ->assertJsonPath('data.attributes.name', 'Aphex Twin');

        $this->actingAs($this->reader());
        $this->writeJsonApi('POST', '/api/artists', $this->artistDocument())
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function updateResolvesThroughTheModelRegisteredPolicy(): void
    {
        $this->actingAs($this->writer());
        $this->writeJsonApi('PATCH', '/api/artists/1', [
            'data' => ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'Radiohead (edited)']],
        ])->assertStatus(200)->assertJsonPath('data.attributes.name', 'Radiohead (edited)');

        $this->actingAs($this->reader());
        $this->writeJsonApi('PATCH', '/api/artists/1', [
            'data' => ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'Nope']],
        ])->assertStatus(403)->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function deleteResolvesThroughTheModelRegisteredPolicyAndRequiresAdmin(): void
    {
        // delete() requires an admin; a write-capable non-admin is denied.
        $this->actingAs($this->writer());
        $this->deleteJsonApi('/api/artists/1')->assertStatus(403)->assertJsonPath('errors.0.status', '403');

        $this->actingAs($this->admin());
        $this->deleteJsonApi('/api/artists/2')->assertStatus(204);
    }

    /**
     * @return array<string, mixed>
     */
    private function artistDocument(): array
    {
        return [
            'data' => [
                'type' => 'artists',
                'attributes' => ['name' => 'Aphex Twin', 'slug' => 'aphex-twin'],
            ],
        ];
    }

    private function writer(): User
    {
        return new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'is_admin' => false]);
    }

    private function reader(): User
    {
        return new User(['id' => 2, 'name' => 'Reader', 'can_write' => false, 'is_admin' => false]);
    }

    private function admin(): User
    {
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => true, 'is_admin' => true]);
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
