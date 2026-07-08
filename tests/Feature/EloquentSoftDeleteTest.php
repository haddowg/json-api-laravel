<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Document;
use Workbench\App\Models\User;
use Workbench\App\Providers\SoftDeleteEloquentServiceProvider;

/**
 * First-class soft deletes on the Eloquent reference layer (Model B): the `documents` type
 * declares `#[AsJsonApiResource(softDeletes: true)]`, which synthesizes a `restore` and a
 * `force-delete` action. Driven end-to-end over HTTP.
 *
 * The lifecycle: `DELETE` soft-deletes (recoverable) → the row 404s on ordinary reads but is
 * discoverable via `filter[onlyTrashed]` carrying `meta.trashed: true` → `restore` un-trashes
 * it → `force-delete` removes it permanently. Restore/force-delete are gated by the model's
 * native `restore()`/`forceDelete()` policy methods, and the restore link renders only on a
 * trashed resource for a requester who could restore it.
 *
 * @internal
 */
final class EloquentSoftDeleteTest extends Orchestra
{
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
            SoftDeleteEloquentServiceProvider::class,
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

        // A document-level security scheme so an ability-secured operation projects its
        // security requirement (without a scheme the projector has nothing to reference).
        $config->set('jsonapi.openapi.security', [
            'schemes' => [
                'bearerAuth' => ['type' => 'bearer', 'bearerFormat' => 'JWT'],
            ],
            'default_requirement' => [
                ['name' => 'bearerAuth'],
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Document::query()->create(['title' => 'Alpha', 'body' => 'first']);
        Document::query()->create(['title' => 'Beta', 'body' => 'second']);
    }

    #[Test]
    #[Group('soft-deletes')]
    public function theDeleteRestoreForceDeleteLifecycle(): void
    {
        $this->actingAs($this->admin());

        // DELETE soft-deletes (recoverable): a 204, the tombstone set, the ordinary read now 404s.
        $this->deleteJsonApi('/api/documents/1')->assertStatus(204);
        $this->readJsonApi('/api/documents/1')->assertStatus(404);
        self::assertTrue(Document::withTrashed()->findOrFail(1)->trashed());

        // The trashed row is discoverable via filter[onlyTrashed], carrying meta.trashed.
        $this->readJsonApi('/api/documents?filter[onlyTrashed]=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.0.meta.trashed', true);

        // restore un-trashes it: a 200 rendering the now-live resource (no trashed flag).
        $this->writeJsonApi('POST', '/api/documents/1/-actions/restore', [])
            ->assertOk()
            ->assertJsonPath('data.type', 'documents')
            ->assertJsonPath('data.id', '1')
            ->assertJsonMissingPath('data.meta.trashed');

        $this->readJsonApi('/api/documents/1')->assertOk()->assertJsonPath('data.id', '1');

        // force-delete removes it permanently: a 204, gone even from a trashed-inclusive query.
        $this->writeJsonApi('POST', '/api/documents/1/-actions/force-delete', [])->assertStatus(204);
        $this->readJsonApi('/api/documents/1')->assertStatus(404);
        self::assertNull(Document::withTrashed()->find(1));
        $this->readJsonApi('/api/documents?filter[onlyTrashed]=1')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    #[Group('soft-deletes')]
    public function withTrashedListsLiveAndTrashedRowsTogether(): void
    {
        $this->actingAs($this->admin());
        $this->deleteJsonApi('/api/documents/1')->assertStatus(204);

        // The default collection excludes the trashed row; filter[withTrashed] includes it.
        $this->readJsonApi('/api/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '2');

        $this->readJsonApi('/api/documents?filter[withTrashed]=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('soft-deletes')]
    #[Group('spec:authorization')]
    public function restoreIsGatedByThePolicyRestoreAbility(): void
    {
        $this->actingAs($this->admin());
        $this->deleteJsonApi('/api/documents/1')->assertStatus(204);

        // The reader is not write-capable → DocumentPolicy::restore() denies (a 403).
        $this->actingAs($this->reader());
        $this->writeJsonApi('POST', '/api/documents/1/-actions/restore', [])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        // A write-capable user passes restore() — restore is gated separately from delete.
        $this->actingAs($this->writer());
        $this->writeJsonApi('POST', '/api/documents/1/-actions/restore', [])->assertOk()->assertJsonPath('data.id', '1');
    }

    #[Test]
    #[Group('soft-deletes')]
    #[Group('spec:authorization')]
    public function forceDeleteRequiresAdminViaThePolicyForceDeleteAbility(): void
    {
        $this->actingAs($this->admin());
        $this->deleteJsonApi('/api/documents/1')->assertStatus(204);

        // A write-capable non-admin passes restore but not forceDelete (a 403).
        $this->actingAs($this->writer());
        $this->writeJsonApi('POST', '/api/documents/1/-actions/force-delete', [])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');

        // Only an admin may permanently destroy.
        $this->actingAs($this->admin());
        $this->writeJsonApi('POST', '/api/documents/1/-actions/force-delete', [])->assertStatus(204);
    }

    #[Test]
    #[Group('soft-deletes')]
    public function theRestoreLinkRendersOnlyOnATrashedResourceForAnAllowedRequester(): void
    {
        // A live document offers no restore link (nothing to restore) — the conditional link.
        $this->actingAs($this->writer());
        $this->readJsonApi('/api/documents/2')->assertOk()->assertJsonMissingPath('data.links.restore');

        $this->actingAs($this->admin());
        $this->deleteJsonApi('/api/documents/1')->assertStatus(204);

        // A trashed document offers the restore link to a write-capable requester...
        $this->actingAs($this->writer());
        $this->readJsonApi('/api/documents?filter[onlyTrashed]=1')
            ->assertOk()
            ->assertJsonPath('data.0.links.restore', fn(mixed $link): bool => \is_string($link) && \str_contains($link, '/api/documents/1/-actions/restore'));

        // ...but never to a requester who could not pass the restore ability.
        $this->actingAs($this->reader());
        $this->readJsonApi('/api/documents?filter[onlyTrashed]=1')
            ->assertOk()
            ->assertJsonMissingPath('data.0.links.restore');
    }

    #[Test]
    #[Group('soft-deletes')]
    #[Group('openapi')]
    public function theSynthesizedActionsAreProjectedIntoTheOpenApiDocumentAsSecuredOperations(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        \assert(\array_is_list($doc) === false);

        // The restore action self-documents as a secured POST returning a 200 document.
        $restore = $this->arrayAt($doc, 'paths', '/documents/{id}/-actions/restore', 'post');
        $this->assertArrayHasKey('200', $this->arrayAt($restore, 'responses'));
        $this->assertNotEmpty($this->arrayAt($restore, 'security'));

        // The force-delete action self-documents as a secured POST returning a 204.
        $force = $this->arrayAt($doc, 'paths', '/documents/{id}/-actions/force-delete', 'post');
        $this->assertArrayHasKey('204', $this->arrayAt($force, 'responses'));
        $this->assertNotEmpty($this->arrayAt($force, 'security'));
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
