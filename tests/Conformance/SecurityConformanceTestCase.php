<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * The authorization half of the dual-provider conformance contract (PLAN decision 7):
 * identical policy assertions run against the in-memory witness
 * ({@see InMemorySecurityConformanceTest}) and the reference Eloquent provider over
 * SQLite ({@see EloquentSecurityConformanceTest}). Because the secured `albums` type
 * declares a **dedicated `AlbumApiPolicy`** — provider-agnostic, authorizing a POPO and
 * an Eloquent model alike — the SAME allowed/denied/unauthenticated outcomes hold on both.
 *
 * The secured `albums` type is served on the auth-guarded `secure` server, so the four
 * cells of the matrix map cleanly: an **unauthenticated** request is a `401` (the auth
 * middleware), an **authenticated-but-denied** one a `403` (the policy), an **allowed**
 * one a `201`/`200`/`204`, and the **policy-less** `genres` type (unguarded `default`
 * server) is inert — no check at all. It also pins the two attribute overrides: the
 * `create`→`publish` **ability rename**, the `delete`→`false` **disable**, and the
 * `AlbumApiPolicy::before()` admin bypass.
 *
 * The Gate model-policy auto-resolution and `Gate::define` paths are Eloquent-only (a
 * POPO carries no Gate policy) — see {@see \haddowg\JsonApiLaravel\Tests\Feature\EloquentGatePolicyAuthorizationTest}.
 */
abstract class SecurityConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The workbench service provider that wires exactly ONE provider/persister pair
     * (in-memory or Eloquent) over the shared secured resources.
     *
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        // The secured `albums` type lives on the auth-guarded `secure` server (so an
        // unauthenticated write is a 401 from the middleware); the policy-less `genres`
        // type lives on the unguarded `default` server (so its inertness is observable
        // without authenticating).
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => ['auth'], 'domain' => null],
        ]);
    }

    /**
     * Seeds the concrete's data layer. The in-memory concrete no-ops; the Eloquent
     * concrete migrates + seeds.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- unauthenticated → 401 (the auth guard on the `secure` server) ---

    #[Test]
    #[Group('spec:authorization')]
    public function anUnauthenticatedListReturns401(): void
    {
        $this->readJsonApi('/secure-api/albums')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.status', '401');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function anUnauthenticatedCreateReturns401AndDoesNotPersist(): void
    {
        $this->writeJsonApi('POST', '/secure-api/albums', $this->albumDocument())
            ->assertStatus(401)
            ->assertJsonPath('errors.0.status', '401');

        // The write never reached the handler: the store is untouched.
        $this->readJsonApi('/secure-api/albums')->assertStatus(401);
        $this->actingAs($this->reader());
        $this->readJsonApi('/secure-api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function guestWritesAndReadOnTheIdScopedRoutesAllReturn401(): void
    {
        // The 401 (auth middleware) arm on the id-scoped routes, which are registered
        // separately from the collection route: an unauthenticated PATCH, DELETE and GET
        // on /secure-api/albums/1 each return 401, so a route-registration bug dropping the
        // server middleware from the id routes would be caught.
        $this->writeJsonApi('PATCH', '/secure-api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Guest Edit']],
        ])->assertStatus(401)->assertJsonPath('errors.0.status', '401');

        $this->deleteJsonApi('/secure-api/albums/1')->assertStatus(401)->assertJsonPath('errors.0.status', '401');

        $this->readJsonApi('/secure-api/albums/1')->assertStatus(401)->assertJsonPath('errors.0.status', '401');

        // The guest PATCH/DELETE never reached the handler: album 1 is untouched.
        $this->actingAs($this->reader());
        $this->readJsonApi('/secure-api/albums/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'OK Computer');
    }

    // --- viewAny / view: any authenticated user is allowed ---

    #[Test]
    #[Group('spec:authorization')]
    public function anyAuthenticatedUserMayListAndRead(): void
    {
        $this->actingAs($this->reader());

        $this->readJsonApi('/secure-api/albums')->assertOk()->assertJsonCount(2, 'data');
        $this->readJsonApi('/secure-api/albums/1')->assertOk()->assertJsonPath('data.id', '1');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aNonReadUserIsDeniedTheListByTheDedicatedPolicy(): void
    {
        // The dedicated policy's `viewAny` denies a user without read access — a `403`
        // BEFORE the query runs, proving read authorization executes on the policy path
        // (not merely the auth middleware). Runs identically on both providers, so the
        // in-memory witness proves the read gate fires too.
        $this->actingAs($this->noAccessUser());

        $this->readJsonApi('/secure-api/albums')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aNonReadUserIsDeniedTheReadOnAnExistingResource(): void
    {
        // The dedicated policy's `view` denies a user without read access — a `403` AFTER
        // the model loads (an existing id, so a miss is not masking it as a 404), proving
        // the single-resource read gate executes on both providers.
        $this->actingAs($this->noAccessUser());

        $this->readJsonApi('/secure-api/albums/1')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
    }

    // --- create (renamed to `publish`) ---

    #[Test]
    #[Group('spec:authorization')]
    public function aWriteCapableUserMayPublishACreate(): void
    {
        $this->actingAs($this->writer());

        $this->writeJsonApi('POST', '/secure-api/albums', $this->albumDocument())
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'albums')
            ->assertJsonPath('data.attributes.title', 'A Secured Album');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aNonWriteUserIsDeniedTheCreateAndNothingPersists(): void
    {
        $this->actingAs($this->reader());

        $this->writeJsonApi('POST', '/secure-api/albums', $this->albumDocument())
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        // Denied before persist: the collection is unchanged.
        $this->readJsonApi('/secure-api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function theRenamedPublishAbilityGatesTheCreate(): void
    {
        // The resource renames `create` to `publish`: AlbumApiPolicy has no `create`
        // method, only `publish`. A write-capable user is allowed only because the Gate
        // resolved the RENAMED ability to `publish()` — proving the per-op ability rename.
        $this->actingAs($this->writer());
        $this->writeJsonApi('POST', '/secure-api/albums', $this->albumDocument())->assertStatus(201);
    }

    #[Test]
    #[Group('spec:authorization')]
    public function anAdminBypassesTheDenialThroughThePolicyBeforeHook(): void
    {
        // The admin is NOT write-capable, so `publish()` would deny — but the policy's
        // before() returns true for an admin, bypassing the per-ability rule. Proving
        // before() is honoured.
        $this->actingAs($this->admin());

        $this->writeJsonApi('POST', '/secure-api/albums', $this->albumDocument())->assertStatus(201);
    }

    // --- update ---

    #[Test]
    #[Group('spec:authorization')]
    public function aWriteCapableUserMayUpdate(): void
    {
        $this->actingAs($this->writer());

        $this->writeJsonApi('PATCH', '/secure-api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Edited Title']],
        ])->assertStatus(200)->assertJsonPath('data.attributes.title', 'Edited Title');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aNonWriteUserIsDeniedTheUpdateAndTheTargetIsUnchanged(): void
    {
        $this->actingAs($this->reader());

        $this->writeJsonApi('PATCH', '/secure-api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Should Not Apply']],
        ])->assertStatus(403)->assertJsonPath('errors.0.status', '403');

        // Denied on the PRISTINE model, before hydration — the title is unchanged.
        $this->readJsonApi('/secure-api/albums/1')->assertJsonPath('data.attributes.title', 'OK Computer');
    }

    // --- delete: the check is disabled (`false`) ---

    #[Test]
    #[Group('spec:authorization')]
    public function deleteIsUngatedByTheFalseOverrideSoANonWriteUserMayDelete(): void
    {
        // A non-write user would be denied every WRITE op — but `delete` is disabled
        // (`Delete => false`), so the authorizer performs no check and the delete
        // succeeds. Proving `false` disables the gate (while the auth middleware still
        // required them to be authenticated).
        $this->actingAs($this->reader());

        $this->deleteJsonApi('/secure-api/albums/1')->assertStatus(204);
        $this->readJsonApi('/secure-api/albums/1')->assertStatus(404);
    }

    // --- policy-less type: inert (no check) ---

    #[Test]
    #[Group('spec:authorization')]
    public function aPolicyLessTypeIsInertAndServesUnauthenticatedReadsAndWrites(): void
    {
        // `genres` declares no policy and no ability overrides, no Gate policy is
        // registered for it, and it sits on the unguarded server — so it is fully inert:
        // an unauthenticated client reads and writes it freely.
        $this->readJsonApi('/api/genres')->assertOk()->assertJsonCount(2, 'data');

        $this->writeJsonApi('POST', '/api/genres', [
            'data' => ['type' => 'genres', 'id' => 'ambient', 'attributes' => ['name' => 'Ambient']],
        ])->assertStatus(201)->assertJsonPath('data.id', 'ambient');

        $this->writeJsonApi('PATCH', '/api/genres/trip-hop', [
            'data' => ['type' => 'genres', 'id' => 'trip-hop', 'attributes' => ['name' => 'Trip-Hop Renamed']],
        ])->assertStatus(200)->assertJsonPath('data.attributes.name', 'Trip-Hop Renamed');

        $this->deleteJsonApi('/api/genres/trip-hop')->assertStatus(204);
    }

    // --- the per-relation READ gate (related + relationship endpoints) ---

    #[Test]
    #[Group('spec:authorization')]
    public function theRelatedReadGateInheritsTheParentViewPolicy(): void
    {
        // The `artist` relation declares no read override, so its related and relationship
        // endpoints inherit the parent album's `view` policy — the SAME gate the single-
        // resource read applies. Unauthenticated is the middleware 401; an authenticated
        // user the policy denies `view` is a 403 on BOTH endpoints; a read-capable user is
        // allowed (a proper 401→403→200 matrix on both providers).
        $this->readJsonApi('/secure-api/albums/1/artist')->assertStatus(401);
        $this->readJsonApi('/secure-api/albums/1/relationships/artist')->assertStatus(401);

        $this->actingAs($this->noAccessUser());
        $this->readJsonApi('/secure-api/albums/1/artist')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
        $this->readJsonApi('/secure-api/albums/1/relationships/artist')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        $this->actingAs($this->reader());
        $this->readJsonApi('/secure-api/albums/1/artist')->assertOk();
        $this->readJsonApi('/secure-api/albums/1/relationships/artist')->assertOk();
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aRelationWithReadSecurityFalseIsReadableDespiteADenyingViewPolicy(): void
    {
        // `publicArtist` declares `security(read: false)` — the read gate is disabled, so a
        // user the parent `view` policy WOULD deny reads it anyway (the middleware still
        // requires authentication, so a guest is still a 401).
        $this->readJsonApi('/secure-api/albums/1/relationships/publicArtist')->assertStatus(401);

        $this->actingAs($this->noAccessUser());
        $this->readJsonApi('/secure-api/albums/1/relationships/publicArtist')->assertOk();
        $this->readJsonApi('/secure-api/albums/1/publicArtist')->assertOk();
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aRelationWithAReadAbilityOverrideIsGatedByThatAbility(): void
    {
        // `guardedArtist` declares `security(read: 'inspectArtist')` — the gate authorizes the
        // RENAMED ability against the parent (Authorizer::authorizeAbility → AlbumApiPolicy::
        // inspectArtist), NOT the default `view`. A read-capable user passes, a no-access user
        // is denied, and the admin bypasses through the policy's before() hook.
        $this->readJsonApi('/secure-api/albums/1/relationships/guardedArtist')->assertStatus(401);

        $this->actingAs($this->noAccessUser());
        $this->readJsonApi('/secure-api/albums/1/relationships/guardedArtist')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        $this->actingAs($this->reader());
        $this->readJsonApi('/secure-api/albums/1/relationships/guardedArtist')->assertOk();
        $this->readJsonApi('/secure-api/albums/1/guardedArtist')->assertOk();

        // The admin has no read access, so inspectArtist() would deny — but before() bypasses.
        $this->actingAs($this->admin());
        $this->readJsonApi('/secure-api/albums/1/relationships/guardedArtist')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function albumDocument(): array
    {
        return [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'A Secured Album',
                    'status' => 'released',
                    'releasedAt' => '2021-03-03T00:00:00+00:00',
                ],
            ],
        ];
    }

    private function writer(): User
    {
        return new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    private function reader(): User
    {
        return new User(['id' => 2, 'name' => 'Reader', 'can_write' => false, 'can_read' => true, 'is_admin' => false]);
    }

    private function admin(): User
    {
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'can_read' => false, 'is_admin' => true]);
    }

    /**
     * A user with neither read nor write access (and not an admin) — denied by every
     * ability the policy gates, including the read path (`viewAny`/`view`).
     */
    private function noAccessUser(): User
    {
        return new User(['id' => 4, 'name' => 'No Access', 'can_write' => false, 'can_read' => false, 'is_admin' => false]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function deleteJsonApi(string $uri): TestResponse
    {
        return $this->call('DELETE', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => self::MEDIA_TYPE,
        ]));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
