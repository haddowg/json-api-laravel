<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\ServerScopedAuthorizationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * `documents` and `notes` are each exposed on `default` and `admin` through a different
 * resource class, and each server enforces only what its own class declares.
 * On `default`, documents follow PublicDocumentPolicy and notes declare nothing. On `admin`,
 * documents follow AdminDocumentPolicy with `update` renamed to `edit` and `delete`
 * disabled, and notes follow AdminNotePolicy, which only an admin passes.
 *
 * @internal
 */
abstract class ServerScopedAuthorizationTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('ssa.store', $this->store());
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('jsonapi.openapi.security', [
            'schemes' => ['bearerAuth' => ['type' => 'bearer']],
            'default_requirement' => [['name' => 'bearerAuth']],
        ]);

        return [JsonApiServiceProvider::class, ServerScopedAuthorizationServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->member());
    }

    #[Test]
    #[Group('spec:authorization')]
    public function eachServerEnforcesItsOwnPolicyAndAbilities(): void
    {
        $this->read('/api/documents')->assertOk()->assertJsonCount(1, 'data');
        $this->read('/admin-api/documents')->assertOk()->assertJsonCount(1, 'data');

        // PublicDocumentPolicy::update refuses everyone; AdminDocumentPolicy::edit only lets an admin in.
        $this->rename('/api/documents/1')->assertForbidden();
        $this->rename('/admin-api/documents/1')->assertForbidden();

        $this->actingAs($this->admin());
        $this->rename('/api/documents/1')->assertForbidden();
        $this->rename('/admin-api/documents/1')->assertOk()->assertJsonPath('data.attributes.title', 'Renamed');

        // The admin server disables the delete check; the default server's policy refuses it.
        $this->actingAs($this->member());
        $this->delete('/api/documents/1', [], ['Accept' => self::MEDIA_TYPE])->assertForbidden();
        $this->delete('/admin-api/documents/1', [], ['Accept' => self::MEDIA_TYPE])->assertNoContent();
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aServerWhoseResourceDeclaresNothingInheritsNoOtherServersPolicy(): void
    {
        $this->read('/api/notes')->assertOk()->assertJsonCount(2, 'data');
        $this->read('/api/notes/1')->assertOk();

        $this->read('/admin-api/notes')->assertForbidden();
        $this->read('/admin-api/notes/1')->assertForbidden();
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aResourceOnSeveralServersIsEnforcedOnEach(): void
    {
        $this->read('/api/labels')->assertForbidden();
        $this->read('/admin-api/labels')->assertForbidden();

        $this->actingAs($this->admin());
        $this->read('/api/labels')->assertOk()->assertJsonCount(1, 'data');
        $this->read('/admin-api/labels')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function anIncludePassesTheRelatedTypesGatesOfItsOwnServer(): void
    {
        $default = $this->read('/api/documents/1?include=notes')->assertOk();
        self::assertSame(['1', '2'], $this->ids($default->json('data.relationships.notes.data')));
        self::assertSame(['1', '2'], $this->ids($default->json('included')));
        $this->read('/api/documents/1/notes')->assertOk()->assertJsonCount(2, 'data');

        $admin = $this->read('/admin-api/documents/1?include=notes')->assertOk();
        $notes = $admin->json('data.relationships.notes');
        self::assertIsArray($notes);
        self::assertArrayNotHasKey('data', $notes);
        $admin->assertJsonMissingPath('included.0');
        $this->read('/admin-api/documents/1/notes')->assertForbidden();
    }

    #[Test]
    #[Group('spec:authorization')]
    public function theRelationPermissionConventionReadsTheServersOwnPolicy(): void
    {
        // PublicDocumentPolicy::viewRevisions refuses, though `notes` on default declares nothing.
        $default = $this->read('/api/documents/1?include=revisions')->assertOk();
        $revisions = $default->json('data.relationships.revisions');
        self::assertIsArray($revisions);
        self::assertArrayNotHasKey('data', $revisions);
        $this->read('/api/documents/1/revisions')->assertForbidden();

        // AdminDocumentPolicy::viewRevisions allows, though AdminNotePolicy would refuse the list.
        $admin = $this->read('/admin-api/documents/1?include=revisions')->assertOk();
        self::assertSame(['1', '2'], $this->ids($admin->json('data.relationships.revisions.data')));
        $this->read('/admin-api/documents/1/revisions')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('openapi')]
    public function eachServersDocumentProjectsItsOwnSecurity(): void
    {
        $default = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        $admin = $this->resolve(DocumentFactory::class)->forServer('admin')->toArray();

        // A dedicated policy secures every operation; on admin, delete is disabled and public.
        self::assertNotEmpty($this->arrayAt($default, 'paths', '/documents/{id}', 'delete', 'security'));
        self::assertSame([], $this->arrayAt($admin, 'paths', '/documents/{id}', 'delete', 'security'));

        // Notes declare nothing on default, so they inherit the document default.
        self::assertArrayNotHasKey('security', $this->arrayAt($default, 'paths', '/notes', 'get'));
        self::assertNotEmpty($this->arrayAt($admin, 'paths', '/notes', 'get', 'security'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function rename(string $uri): TestResponse
    {
        return $this->call('PATCH', $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => ['type' => 'documents', 'id' => '1', 'attributes' => ['title' => 'Renamed']]]));
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $resources): array
    {
        self::assertIsArray($resources);
        $ids = \array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $resources);
        \sort($ids);

        return $ids;
    }

    private function member(): User
    {
        return new User(['id' => 1, 'name' => 'Member', 'is_admin' => false]);
    }

    private function admin(): User
    {
        return new User(['id' => 2, 'name' => 'Admin', 'is_admin' => true]);
    }
}
