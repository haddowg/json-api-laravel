<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\GizmoResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The resource lifecycle-hook trait (PLAN decision 10) is sugar routed off the events by
 * the {@see \haddowg\JsonApiLaravel\EventListener\ResourceHookSubscriber}: a before-hook
 * aborts by throwing, an after-hook may replace the response. Driven over HTTP on the
 * `gizmos` fixture ({@see GizmoResource}), which delegates each hook to a test-installed
 * static callback so one resource exercises the whole abort/replace matrix.
 *
 * @internal
 */
final class ResourceHookTest extends Orchestra
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
            LifecycleServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        GizmoResource::reset();
    }

    protected function tearDown(): void
    {
        GizmoResource::reset();
        parent::tearDown();
    }

    #[Test]
    #[Group('events')]
    public function anAfterFetchOneHookReplacesTheResponse(): void
    {
        GizmoResource::$callbacks['afterFetchOne'] = fn(object $entity): DataResponse => $this->gizmoResponse($entity, 'afterFetchOne');

        $this->readJsonApi('/api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('meta.hooked', 'afterFetchOne');
    }

    #[Test]
    #[Group('events')]
    public function anAfterFetchCollectionHookReplacesTheResponseAndReceivesTheItems(): void
    {
        $seen = null;
        GizmoResource::$callbacks['afterFetchCollection'] = function (array $items) use (&$seen): DataResponse {
            $seen = \count($items);
            $serializer = $this->resolve(ServerRegistry::class)->get()->serializerFor('gizmos');

            return DataResponse::fromCollection($items, $serializer)->withMeta(['hooked' => 'afterFetchCollection']);
        };

        $this->readJsonApi('/api/gizmos')
            ->assertOk()
            ->assertJsonPath('meta.hooked', 'afterFetchCollection');

        $this->assertSame(1, $seen, 'the after-fetch-collection hook receives the materialized items');
    }

    #[Test]
    #[Group('events')]
    public function anAfterCreateHookReplacesTheResponse(): void
    {
        GizmoResource::$callbacks['afterCreate'] = fn(object $entity): DataResponse => $this->gizmoResponse($entity, 'afterCreate')->withStatus(201);

        $this->writeJsonApi('POST', '/api/gizmos', [
            'data' => ['type' => 'gizmos', 'attributes' => ['name' => 'New', 'status' => 'draft']],
        ])
            ->assertStatus(201)
            ->assertJsonPath('meta.hooked', 'afterCreate');
    }

    #[Test]
    #[Group('events')]
    public function aBeforeCreateHookAbortsByThrowing(): void
    {
        GizmoResource::$callbacks['beforeCreate'] = static function (): void {
            throw new ResourceNotFound();
        };

        $this->writeJsonApi('POST', '/api/gizmos', [
            'data' => ['type' => 'gizmos', 'attributes' => ['name' => 'Doomed', 'status' => 'draft']],
        ])->assertStatus(404);

        // The throw aborted before persist: the store still holds only the seeded gizmo.
        $this->readJsonApi('/api/gizmos')->assertJsonCount(1, 'data');
    }

    #[Test]
    #[Group('events')]
    public function aBeforeUpdateHookAbortsByThrowing(): void
    {
        GizmoResource::$callbacks['beforeUpdate'] = static function (): void {
            throw new ResourceNotFound();
        };

        $this->writeJsonApi('PATCH', '/api/gizmos/1', [
            'data' => ['type' => 'gizmos', 'id' => '1', 'attributes' => ['status' => 'changed']],
        ])->assertStatus(404);

        // The throw rolled the write back: the gizmo's status is unchanged.
        $this->readJsonApi('/api/gizmos/1')->assertJsonPath('data.attributes.status', 'draft');
    }

    #[Test]
    #[Group('events')]
    public function aResourceWithNoInstalledHookRendersNormally(): void
    {
        $this->readJsonApi('/api/gizmos/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.name', 'Original')
            ->assertJsonMissingPath('meta.hooked');
    }

    private function gizmoResponse(object $entity, string $marker): DataResponse
    {
        $serializer = $this->resolve(ServerRegistry::class)->get()->serializerFor('gizmos');

        return DataResponse::fromResource($entity, $serializer)->withMeta(['hooked' => $marker]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $abstract
     *
     * @return T
     */
    private function resolve(string $abstract): object
    {
        $app = $this->app;
        $this->assertInstanceOf(\Illuminate\Foundation\Application::class, $app);

        $instance = $app->make($abstract);
        $this->assertInstanceOf($abstract, $instance);

        return $instance;
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
