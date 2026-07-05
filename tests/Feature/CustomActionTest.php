<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * The custom-action surface (PLAN decision 12): `POST /albums/{id}/-actions/publish`, a
 * resource-scope, Document-input action gated by the `publish` Gate ability and exposed as
 * an ability-aware `links` member (`asLink`). Driven end-to-end over HTTP on the in-memory
 * surface wiring — the action subsystem (routing, input parsing, entity resolution,
 * ability enforcement, handler dispatch) is provider-agnostic, so the in-memory witness is
 * a faithful driver of the whole path.
 *
 * @internal
 */
final class CustomActionTest extends Orchestra
{
    use InteractsWithJsonApi;
    use InteractsWithOpenApiDocument;

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
            SurfaceInMemoryServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('actions')]
    public function aWriteCapableUserPublishesAnAlbumAndTheStatusPersists(): void
    {
        $this->actingAs($this->writer());

        $this->writeJsonApi('POST', '/api/albums/1/-actions/publish', [
            'data' => ['type' => 'albums', 'attributes' => ['status' => 'released']],
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'albums')
            ->assertJsonPath('data.id', '1')
            ->assertJsonPath('data.attributes.status', 'released');

        // The action persisted through the `albums` persister — a follow-up read sees it.
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.status', 'released');
    }

    #[Test]
    #[Group('actions')]
    public function aNonWriteUserIsDeniedThePublishActionWithA403(): void
    {
        $this->actingAs($this->reader());

        $this->writeJsonApi('POST', '/api/albums/1/-actions/publish', [
            'data' => ['type' => 'albums', 'attributes' => ['status' => 'released']],
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        // Denied before the handler ran: the album is unchanged.
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.status', 'draft');
    }

    #[Test]
    #[Group('actions')]
    public function aGuestIsDeniedThePublishActionWithA403(): void
    {
        // The surface `default` server carries no auth middleware, so a guest reaches the
        // handler; the `publish` ability gate (a null user is not write-capable) denies it.
        $this->writeJsonApi('POST', '/api/albums/1/-actions/publish', [
            'data' => ['type' => 'albums', 'attributes' => ['status' => 'released']],
        ])->assertStatus(403);
    }

    #[Test]
    #[Group('actions')]
    public function aCollectionScopeActionAbilityIsEnforcedNotFailOpen(): void
    {
        // The `purge` collection-scope action carries no `{id}`, so the ability is authorized
        // against the resource-class token via the Gate closure. A guest, a read-only user and
        // a write-but-not-admin user must all be denied — a regression that fails the gate open
        // (no subject → no check) would 2xx here for any caller.
        $this->writeJsonApi('POST', '/api/albums/-actions/purge', [])->assertStatus(403);

        $this->actingAs($this->reader());
        $this->writeJsonApi('POST', '/api/albums/-actions/purge', [])->assertStatus(403);

        $this->actingAs($this->writer());
        $this->writeJsonApi('POST', '/api/albums/-actions/purge', [])->assertStatus(403);
    }

    #[Test]
    #[Group('actions')]
    public function anAdminPassesTheCollectionScopeActionGate(): void
    {
        $this->actingAs($this->admin());

        $this->writeJsonApi('POST', '/api/albums/-actions/purge', [])
            ->assertOk()
            ->assertJsonPath('meta.purged', true);
    }

    #[Test]
    #[Group('actions')]
    public function publishingAMissingAlbumIsA404(): void
    {
        $this->actingAs($this->writer());

        $this->writeJsonApi('POST', '/api/albums/999/-actions/publish', [
            'data' => ['type' => 'albums', 'attributes' => ['status' => 'released']],
        ])->assertStatus(404);
    }

    #[Test]
    #[Group('actions')]
    public function anUndeclaredActionPathHasNoRouteAndIs404(): void
    {
        $this->actingAs($this->writer());

        // No route is registered for an undeclared action name, so the router 404s.
        $this->writeJsonApi('POST', '/api/albums/1/-actions/archive', [
            'data' => ['type' => 'albums', 'attributes' => []],
        ])->assertNotFound();
    }

    #[Test]
    #[Group('actions')]
    #[Group('openapi')]
    public function theActionIsProjectedIntoTheOpenApiDocument(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        \assert(\array_is_list($doc) === false);

        // Core's OperationProjector mounts the resource-scope action under the reserved
        // `-actions` segment with its declared method, off the ActionMetadata the registry
        // feeds the metadata source.
        $operation = $this->arrayAt($doc, 'paths', '/albums/{id}/-actions/publish', 'post');
        $this->assertArrayHasKey('responses', $operation);

        // Document input mode → the projector emits a requestBody for the action (the
        // `<inputType>` create request), proving the ActionMetadata's inputMode was threaded.
        $this->assertArrayHasKey('requestBody', $operation);
    }

    #[Test]
    #[Group('actions')]
    #[Group('openapi')]
    public function theTypesExplicitTagFlowsToItsOperationsAndActions(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        \assert(\array_is_list($doc) === false);

        // The albums type declares #[AsJsonApiResource(tags: ['Catalog'])], so its CRUD
        // operations carry the explicit tag rather than the humanized default 'Albums'.
        $show = $this->arrayAt($doc, 'paths', '/albums/{id}', 'get');
        $this->assertSame(['Catalog'], $show['tags'] ?? null);

        // The publish action declares no tags of its own, so it inherits the mount type's
        // explicit tag (the bundle's withResolvedTags parity) — not 'Albums'.
        $publish = $this->arrayAt($doc, 'paths', '/albums/{id}/-actions/publish', 'post');
        $this->assertSame(['Catalog'], $publish['tags'] ?? null);
    }

    #[Test]
    #[Group('actions')]
    public function theAsLinkActionRendersOnlyForARequesterThatWouldPassTheGate(): void
    {
        // A write-capable requester would pass the `publish` gate, so the album's rendered
        // `links` carry the action URL.
        $this->actingAs($this->writer());
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.links.publish', fn(mixed $link): bool => \is_string($link) && \str_contains($link, '/api/albums/1/-actions/publish'));

        // A read-only requester would be denied, so the link is suppressed — a client never
        // sees a link to an action it cannot invoke.
        $this->actingAs($this->reader());
        $this->readJsonApi('/api/albums/1')->assertJsonMissingPath('data.links.publish');
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
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => true, 'can_read' => true, 'is_admin' => true]);
    }

    /**
     * POST an action document through the shipped {@see InteractsWithJsonApi} kit (which
     * negotiates the JSON:API media type). The `$this->actingAs()` set on the test case
     * before this call is honoured — the kit dispatches through the same native test
     * client, so authentication composes natively (PLAN decision 12).
     *
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        $request = $this->jsonApi()->withDocument($document);

        return match (\strtoupper($method)) {
            'POST' => $request->post($uri),
            'PATCH' => $request->patch($uri),
            'DELETE' => $request->delete($uri),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported JSON:API write method "%s".', $method)),
        };
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->jsonApi()->get($uri);
    }
}
