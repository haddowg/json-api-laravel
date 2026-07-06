<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The async-write seam acceptance suite (ADR 0020), asserted byte-identical over HTTP on
 * the in-memory witness ({@see InMemoryAsyncWriteConformanceTest}) and the reference
 * Eloquent provider ({@see EloquentAsyncWriteConformanceTest}). An `albums` persister that
 * accepts every write for asynchronous processing
 * ({@see \Workbench\App\Async\AsyncAlbumsPersister}) makes `POST`/`PATCH /albums` render a
 * `202 Accepted` with `Content-Location` + `Retry-After` pointing at a pollable job
 * resource; a completion action drives the `303 See Other` leg; and an async accept inside
 * an Atomic Operations batch is refused as a `422`, rolling the batch back — the full
 * JSON:API asynchronous-processing lifecycle, the Laravel twin of the Symfony bundle's
 * `AsyncWriteTest`.
 *
 * The seam is provider-agnostic (the handler owns the `202`/`303` render off core's
 * framework-neutral response VOs, the persister owns the accept decision), so the two
 * concretes differ only in the wiring service provider (and the Eloquent one's migrate +
 * seed of the matching baseline).
 */
abstract class AsyncWriteConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * The workbench service provider that wires exactly ONE provider/persister pair
     * (in-memory or Eloquent) with the async `albums` persister shadowing it.
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
        // Pin the base URI to the request origin + the `api` prefix so the completion
        // action's `303` Location and the persister's `Content-Location` resolve to the
        // routes a client GETs.
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        // The atomic-rejection case posts to `POST /api/operations`.
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    /**
     * Seeds the concrete's data layer. The in-memory concrete no-ops (the provider
     * registration seeds); the Eloquent concrete migrates + seeds.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingAResourceIsAcceptedForAsynchronousProcessing(): void
    {
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'Queued for later',
                    'status' => 'released',
                    'releasedAt' => '2020-02-02T00:00:00+00:00',
                ],
            ],
        ]);

        $response->assertStatus(202);
        $response->assertHeader('Content-Location', 'http://localhost/api/jobs/job-1');
        $response->assertHeader('Retry-After', '30');

        // The 202 body is the pollable job resource, rendered through the jobs serializer.
        $response->assertJsonPath('data.type', 'jobs');
        $response->assertJsonPath('data.id', 'job-1');
        $response->assertJsonPath('data.attributes.status', 'queued');

        // Nothing was committed: the accepted create is not readable (the store is
        // unchanged — the seeded albums are 1, not the would-be created row).
        $this->readJsonApi('/api/albums/2')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAResourceIsAcceptedForAsynchronousProcessing(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['title' => 'An async edit'],
            ],
        ]);

        $response->assertStatus(202);
        $response->assertHeader('Content-Location', 'http://localhost/api/jobs/job-2');
        $response->assertHeader('Retry-After', '30');

        $response->assertJsonPath('data.type', 'jobs');
        $response->assertJsonPath('data.id', 'job-2');
        $response->assertJsonPath('data.attributes.status', 'queued');
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCompletionActionRedirectsWithSeeOther(): void
    {
        $response = $this->writeJsonApi('POST', '/api/jobs/-actions/complete', []);

        $response->assertStatus(303);
        $response->assertHeader('Location', 'http://localhost/api/albums/1');
        $this->assertSame('', $response->getContent());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAsyncAcceptInsideAnAtomicBatchIsRefusedAndRollsBack(): void
    {
        $before = $this->readJsonApi('/api/artists')->json('data');
        $this->assertIsArray($before);
        $baseline = \count($before);

        // op0 creates an artist synchronously (the reference persister); op1 creates an
        // album, which the async persister accepts for asynchronous processing — incompatible
        // with the batch's all-or-nothing commit, so it fails the sub-operation.
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Ghost', 'slug' => 'ghost']]],
            ['op' => 'add', 'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Deferred', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
            ]],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ASYNC_WRITE_IN_ATOMIC_OPERATION');

        // The failure is reported at the failing operation's index (op1).
        $pointer = $response->json('errors.0.source.pointer');
        $this->assertIsString($pointer);
        $this->assertStringStartsWith('/atomic:operations/1', $pointer);

        // The whole batch rolled back: the op0 artist was NOT persisted.
        $after = $this->readJsonApi('/api/artists')->json('data');
        $this->assertIsArray($after);
        $this->assertCount($baseline, $after);
    }

    /**
     * POST/PATCH a JSON:API document through the shipped {@see InteractsWithJsonApi} kit,
     * which negotiates the JSON:API media type on both the request `Content-Type` and the
     * `Accept` header.
     *
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        $request = $this->jsonApi()->withDocument($document);

        return match (\strtoupper($method)) {
            'POST' => $request->post($uri),
            'PATCH' => $request->patch($uri),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported JSON:API write method "%s".', $method)),
        };
    }

    /**
     * GET a resource, negotiating the JSON:API media type.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function readJsonApi(string $uri): TestResponse
    {
        return $this->jsonApi()->get($uri);
    }

    /**
     * Issues an Atomic Operations batch against `POST /api/operations`, carrying the atomic
     * `ext` media-type parameter on both Content-Type and Accept.
     *
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        $body = (string) \json_encode(['atomic:operations' => $operations]);

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], $body);
    }
}
