<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Operation\SingletonIdResolver;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Security\BearerTokenScopes;
use Workbench\App\Singleton\InboxScenario;

/**
 * A singleton type: each user's inbox, served at `/inbox` with no id. Ada (user 1) owns
 * inbox 1 holding items 1 and 2 and labelled urgent, Bob (user 2) owns inbox 2 holding item
 * 3, user 3's inbox is missing and a guest has none. The inbox resolves the caller's id
 * once per request, and fetch, update, its relationships, its action and Atomic Operations
 * then run on that id. Runs on the in-memory witness ({@see InMemorySingletonConformanceTest})
 * and Eloquent ({@see EloquentSingletonConformanceTest}).
 */
abstract class SingletonConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;
    use InteractsWithOpenApiDocument;

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
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
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.base_uri', 'https://example.test/api');
        $config->set('logging.default', 'null');
        $config->set('jsonapi.openapi.security', [
            'schemes' => ['oauth2' => [
                'type' => 'oauth2',
                'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.test/oauth/token', 'scopes' => []]],
            ]],
            'scope_scheme' => 'oauth2',
        ]);

        $app->singleton(GrantedScopesProviderInterface::class, BearerTokenScopes::class);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        InboxScenario::reset();
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        InboxScenario::reset();
        parent::tearDown();
    }

    #[Test]
    public function aFetchReadsTheCallersResource(): void
    {
        $this->actingAs($this->user(1));

        $response = $this->jsonApi()->get('/api/inbox')->assertOk();

        $response->assertJsonPath('data.type', 'inboxes');
        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.attributes.title', 'Ada inbox');
        $response->assertJsonPath('data.links.self.href', 'https://example.test/api/inbox');
        $response->assertJsonPath('data.relationships.items.links.self.href', 'https://example.test/api/inbox/relationships/items');
        $response->assertJsonPath('data.relationships.items.links.related.href', 'https://example.test/api/inbox/items');
        $response->assertJsonPath('links.self.href', 'https://example.test/api/inbox');

        $this->actingAs($this->user(2));
        $this->jsonApi()->get('/api/inbox')->assertOk()->assertJsonPath('data.id', '2');
    }

    #[Test]
    public function theCallersIdIsResolvedOncePerRequestFromTheUserAndTheRequest(): void
    {
        $user = $this->user(1);
        $this->actingAs($user);

        $this->jsonApi()->get('/api/inbox?include=items')->assertOk();

        self::assertCount(1, InboxScenario::$contexts);
        $context = InboxScenario::$contexts[0];
        self::assertSame('inboxes', $context->type);
        self::assertNotNull($context->request);
        self::assertSame($user, $context->attribute(SingletonIdResolver::USER_ATTRIBUTE));
        self::assertInstanceOf(Request::class, $context->attribute(SingletonIdResolver::REQUEST_ATTRIBUTE));
    }

    #[Test]
    public function aCallerWithNoResourceGetsA404(): void
    {
        $this->jsonApi()->get('/api/inbox')->assertStatus(404)->assertJsonPath('errors.0.code', 'RESOURCE_NOT_FOUND');
        $this->jsonApi()->get('/api/inbox/relationships/items')->assertStatus(404);
        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/inbox/-actions/archive')->assertStatus(404);
    }

    #[Test]
    public function aMissingRowIsA404(): void
    {
        $this->actingAs($this->user(3));

        $this->jsonApi()->get('/api/inbox')->assertStatus(404)->assertJsonPath('errors.0.code', 'RESOURCE_NOT_FOUND');
        $this->jsonApi()->withDocument($this->inbox('99', 'Nobody'))->patch('/api/inbox')->assertStatus(404);
    }

    #[Test]
    public function anUpdateChangesTheCallersResource(): void
    {
        $this->actingAs($this->user(1));

        $response = $this->jsonApi()->withDocument($this->inbox('1', 'Renamed'))->patch('/api/inbox')->assertOk();

        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.attributes.title', 'Renamed');
        $response->assertJsonPath('data.links.self.href', 'https://example.test/api/inbox');
        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'Renamed');
    }

    #[Test]
    public function anUpdateNamingAnotherIdIsA409(): void
    {
        $this->actingAs($this->user(1));

        $this->jsonApi()->withDocument($this->inbox('2', 'Hijacked'))->patch('/api/inbox')->assertStatus(409);

        $this->actingAs($this->user(2));
        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'Bob inbox');
    }

    #[Test]
    public function aCreateOrADeleteAtTheTypesPathIsA405(): void
    {
        $this->actingAs($this->user(1));

        $this->jsonApi()->withDocument($this->inbox(null, 'Another'))->post('/api/inbox')
            ->assertStatus(405)->assertHeader('Allow', 'GET, HEAD, PATCH');
        $this->jsonApi()->delete('/api/inbox')->assertStatus(405);
    }

    #[Test]
    public function theTypeHasNoCollectionOrIdPath(): void
    {
        $this->actingAs($this->user(1));

        $this->jsonApi()->get('/api/inboxes')->assertStatus(404);
        $this->jsonApi()->get('/api/inbox/1')->assertStatus(404);
    }

    #[Test]
    public function itsRelationshipAndRelatedEndpointsReadTheCallersResource(): void
    {
        $this->actingAs($this->user(1));

        $linkage = $this->jsonApi()->get('/api/inbox/relationships/items')->assertOk();
        self::assertSame(['1', '2'], $this->ids($linkage));
        $linkage->assertJsonPath('links.self.href', 'https://example.test/api/inbox/relationships/items');
        $linkage->assertJsonPath('links.related.href', 'https://example.test/api/inbox/items');

        $related = $this->jsonApi()->get('/api/inbox/items')->assertOk();
        self::assertSame(['1', '2'], $this->ids($related));
        $related->assertJsonPath('data.0.links.self.href', 'https://example.test/api/inboxItems/1');
    }

    #[Test]
    public function aRelationshipWithPivotFieldsLinksAtTheTypesPath(): void
    {
        $this->actingAs($this->user(1));

        $response = $this->jsonApi()->get('/api/inbox/relationships/taggedLabels')->assertOk();

        $response->assertJsonPath('data.0.meta.pivot.tag', '#urgent');
        $response->assertJsonPath('links.self.href', 'https://example.test/api/inbox/relationships/taggedLabels');
        $response->assertJsonPath('links.related.href', 'https://example.test/api/inbox/taggedLabels');
    }

    #[Test]
    public function itsRelationshipsAreWrittenOnTheCallersResource(): void
    {
        $this->actingAs($this->user(1));

        $this->jsonApi()->withDocument(['data' => [['type' => 'inboxLabels', 'id' => '2']]])->post('/api/inbox/relationships/labels')->assertSuccessful();
        self::assertSame(['1', '2'], $this->ids($this->jsonApi()->get('/api/inbox/relationships/labels')));

        $this->jsonApi()->withDocument(['data' => [['type' => 'inboxLabels', 'id' => '2']]])->patch('/api/inbox/relationships/labels')->assertSuccessful();
        self::assertSame(['2'], $this->ids($this->jsonApi()->get('/api/inbox/relationships/labels')));

        $this->json('DELETE', '/api/inbox/relationships/labels', ['data' => [['type' => 'inboxLabels', 'id' => '2']]], [
            'Accept' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ])->assertSuccessful();
        self::assertSame([], $this->ids($this->jsonApi()->get('/api/inbox/relationships/labels')));

        $this->actingAs($this->user(2));
        self::assertSame([], $this->ids($this->jsonApi()->get('/api/inbox/relationships/labels')));
    }

    #[Test]
    public function anotherTypeIncludesItByItsId(): void
    {
        $this->actingAs($this->user(1));

        $response = $this->jsonApi()->get('/api/inboxItems/1?include=inbox')->assertOk();

        $response->assertJsonPath('data.relationships.inbox.data', ['type' => 'inboxes', 'id' => '1']);
        $response->assertJsonPath('included.0.type', 'inboxes');
        $response->assertJsonPath('included.0.id', '1');
        $response->assertJsonPath('included.0.links.self.href', 'https://example.test/api/inbox');
        $response->assertJsonPath('included.0.relationships.items.links.related.href', 'https://example.test/api/inbox/items');
    }

    #[Test]
    public function anotherTypesRelatedEndpointRendersIt(): void
    {
        $this->actingAs($this->user(1));

        $response = $this->jsonApi()->get('/api/inboxItems/2/inbox')->assertOk();

        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.links.self.href', 'https://example.test/api/inbox');
    }

    #[Test]
    public function anActionRunsOnTheCallersResource(): void
    {
        $this->actingAs($this->user(2));

        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.links.archive.href', 'https://example.test/api/inbox/-actions/archive');

        $response = $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/inbox/-actions/archive')->assertOk();

        $response->assertJsonPath('data.id', '2');
        $response->assertJsonPath('data.attributes.title', 'archived');
        $this->actingAs($this->user(1));
        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'Ada inbox');
    }

    #[Test]
    public function anAtomicOperationUpdatesItByRef(): void
    {
        $this->actingAs($this->user(1));

        $this->atomic([['op' => 'update', 'ref' => ['type' => 'inboxes', 'id' => '1'], 'data' => $this->inbox('1', 'By ref')['data']]])
            ->assertOk()
            ->assertJsonPath('atomic:results.0.data.links.self.href', 'https://example.test/api/inbox');

        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'By ref');
    }

    #[Test]
    public function anAtomicOperationResolvesItsHrefForTheCaller(): void
    {
        $this->actingAs($this->user(1));

        $this->atomic([
            ['op' => 'update', 'href' => '/api/inbox', 'data' => $this->inbox('1', 'By href')['data']],
            ['op' => 'add', 'href' => '/api/inbox/relationships/labels', 'data' => [['type' => 'inboxLabels', 'id' => '2']]],
        ])->assertOk();

        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'By href');
        self::assertSame(['1', '2'], $this->ids($this->jsonApi()->get('/api/inbox/relationships/labels')));
        self::assertCount(3, InboxScenario::$contexts);
    }

    #[Test]
    public function anAtomicHrefForACallerWithNoResourceIsA404(): void
    {
        $this->atomic([['op' => 'update', 'href' => '/api/inbox', 'data' => $this->inbox('1', 'Nobody')['data']]])->assertStatus(404);
    }

    #[Test]
    public function anAtomicAddAtTheTypesPathIsRefused(): void
    {
        $this->actingAs($this->user(1));

        $this->atomic([['op' => 'add', 'href' => '/api/inbox', 'data' => $this->inbox(null, 'Another')['data']]])
            ->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'ATOMIC_HREF_INVALID')
            ->assertJsonPath('errors.0.source.pointer', '/atomic:operations/0/href');
        $this->atomic([['op' => 'add', 'data' => $this->inbox(null, 'Another')['data']]])->assertStatus(403);
    }

    #[Test]
    public function itsScopesAreThoseOfOneResource(): void
    {
        $this->actingAs($this->user(1));

        $this->jsonApi()->withHeader('Authorization', 'Bearer ada#')->get('/api/inbox')->assertOk();
        $this->jsonApi()->withHeader('Authorization', 'Bearer ada#')->withDocument($this->inbox('1', 'Nope'))->patch('/api/inbox')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_SCOPE');
        $this->jsonApi()->withHeader('Authorization', 'Bearer ada#inbox:write')->withDocument($this->inbox('1', 'Scoped'))->patch('/api/inbox')->assertOk();
    }

    #[Test]
    public function theDocumentDescribesItsPathsWithNoId(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer('default')->toArray();

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $inboxPaths = \array_values(\array_filter(\array_keys($paths), static fn(int|string $path): bool => \str_starts_with((string) $path, '/inbox') && !\str_starts_with((string) $path, '/inboxItems') && !\str_starts_with((string) $path, '/inboxLabels')));
        \sort($inboxPaths);

        self::assertSame([
            '/inbox',
            '/inbox/-actions/archive',
            '/inbox/items',
            '/inbox/labels',
            '/inbox/relationships/items',
            '/inbox/relationships/labels',
            '/inbox/relationships/taggedLabels',
            '/inbox/taggedLabels',
        ], $inboxPaths);
        self::assertIsArray($paths['/inbox']);
        self::assertSame(['get', 'patch'], \array_keys($paths['/inbox']));
        self::assertSame('/inbox', $this->at($document, 'components', 'schemas', 'InboxesResource', 'x-singleton'));
    }

    protected function user(int $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    protected function inbox(?string $id, string $title): array
    {
        $data = ['type' => 'inboxes', 'attributes' => ['title' => $title]];
        if ($id !== null) {
            $data['id'] = $id;
        }

        return ['data' => $data];
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function ids(TestResponse $response): array
    {
        $data = $response->assertOk()->json('data');
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }
        \sort($ids);

        return $ids;
    }
}
