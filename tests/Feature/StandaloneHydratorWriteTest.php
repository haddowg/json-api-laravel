<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\StandaloneHydratorServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * The write-capable standalone pair end to end (the Laravel twin of the bundle's ADR
 * 0024 write half): the `beacons` type — a standalone `#[AsJsonApiSerializer]` +
 * `#[AsJsonApiHydrator]`, zero `AbstractResource` — creates, updates and deletes
 * through the generic write pipeline over the in-memory provider/persister pair, and
 * the hydrator-only `ingest-commands` type registers its write shape with core while
 * exposing **no** endpoints (the operation-gating default: a hydrator carries no
 * allow-list of its own).
 *
 * @internal
 */
final class StandaloneHydratorWriteTest extends Orchestra
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
            StandaloneHydratorServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        // Pin the base URI to the request origin + the `api` prefix so the created
        // resource's Location resolves to the route a client GETs.
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    #[Test]
    public function the_allow_list_emits_all_five_routes_for_the_hydrator_paired_type(): void
    {
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $routes = $router->getRoutes();

        foreach (['index', 'create', 'show', 'update', 'delete'] as $action) {
            self::assertNotNull($routes->getByName('jsonapi.beacons.' . $action), "Route jsonapi.beacons.{$action} should be registered.");
        }
    }

    #[Test]
    public function creating_through_the_standalone_pair_returns_201_with_location(): void
    {
        $response = $this->writeJsonApi('POST', '/api/beacons', [
            'data' => [
                'type' => 'beacons',
                'attributes' => ['label' => 'Harbour Light'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'beacons');
        // The store mints the next sequential id past the single seeded row.
        $response->assertJsonPath('data.id', '2');
        $response->assertJsonPath('data.attributes.label', 'Harbour Light');
        $response->assertHeader('Location', 'http://localhost/api/beacons/2');

        // The created resource is persisted: a follow-up read returns it.
        $this->readJsonApi('/api/beacons/2')
            ->assertOk()
            ->assertJsonPath('data.attributes.label', 'Harbour Light');
    }

    #[Test]
    public function updating_through_the_standalone_pair_returns_200_and_persists(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/beacons/1', [
            'data' => [
                'type' => 'beacons',
                'id' => '1',
                'attributes' => ['label' => 'Relit Lighthouse'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.label', 'Relit Lighthouse');

        $this->readJsonApi('/api/beacons/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.label', 'Relit Lighthouse');
    }

    #[Test]
    public function deleting_through_the_standalone_pair_returns_204_then_404(): void
    {
        // A resource delete carries no request body (and so no Content-Type).
        $this->delete('/api/beacons/1', [], ['Accept' => self::MEDIA_TYPE])->assertNoContent();

        $this->readJsonApi('/api/beacons/1')->assertNotFound();
    }

    #[Test]
    public function a_hydrator_only_type_registers_with_core_but_exposes_no_endpoints(): void
    {
        // The operation-gating default: a hydrator declares no allow-list, so a
        // hydrator-only type emits no routes at all …
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);
        $routes = $router->getRoutes();

        foreach (['index', 'create', 'show', 'update', 'delete'] as $action) {
            self::assertNull($routes->getByName('jsonapi.ingest-commands.' . $action), "Route jsonapi.ingest-commands.{$action} should NOT be registered.");
        }

        // … while its write shape IS registered with core (a bare hydrator-only pair),
        // resolvable through hydratorFor() — e.g. for a custom action's decoupled
        // inputType document.
        $server = $this->app?->make(ServerRegistry::class)->get();
        self::assertNotNull($server);
        self::assertTrue($server->hasHydratorFor('ingest-commands'));
        self::assertFalse($server->hasSerializerFor('ingest-commands'));
        self::assertFalse($server->hasResourceFor('ingest-commands'));
    }

    #[Test]
    public function the_openapi_document_projects_the_fieldless_write_operations(): void
    {
        // The write verbs the allow-list opens project for the fieldless standalone type
        // exactly as its fetch verbs do — the resource-less write pipeline is documented,
        // not just routed.
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        \assert(\array_is_list($doc) === false);

        $collection = $this->arrayAt($doc, 'paths', '/beacons');
        $this->assertArrayHasKey('get', $collection);
        $this->assertArrayHasKey('post', $collection);

        $resource = $this->arrayAt($doc, 'paths', '/beacons/{id}');
        $this->assertArrayHasKey('get', $resource);
        $this->assertArrayHasKey('patch', $resource);
        $this->assertArrayHasKey('delete', $resource);

        // Still fieldless: the write capability adds no field inventory, so the resource
        // object's attributes stay the inline permissive object.
        $attributes = $this->arrayAt($doc, 'components', 'schemas', 'BeaconsResource', 'properties', 'attributes');
        $this->assertSame(['type' => 'object'], $attributes);

        // The hydrator-only type has no serializer (no wire shape), so it is not
        // documented at all.
        $paths = $this->arrayAt($doc, 'paths');
        $this->assertArrayNotHasKey('/ingest-commands', $paths);
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
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
