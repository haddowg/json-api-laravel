<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JSON:API **Atomic Operations** acceptance suite (PLAN decision 12), asserted
 * byte-identical over HTTP on the in-memory witness ({@see InMemoryAtomicConformanceTest})
 * and the reference Eloquent provider ({@see EloquentAtomicConformanceTest}) over a matching
 * baseline (artists 1/2, album 1). The whole loop — parse, ext negotiation, in-order
 * execution, local-id resolution, all-or-nothing commit/rollback, pointer-prefixed errors —
 * is provider-agnostic (core owns it; the package supplies the transactional backend), so
 * the in-memory witness is the ground truth the Eloquent savepoint-nesting must match.
 *
 * The batch endpoint is opt-in: this suite enables it via {@see defineEnvironment()};
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\AtomicDisabledTest} asserts the disabled 404.
 */
abstract class AtomicConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * The workbench service provider that wires exactly ONE provider/persister pair
     * (in-memory or Eloquent) over the writable surface resources.
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
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    /**
     * Seeds the concrete's data layer. The in-memory concrete no-ops (the surface provider
     * seeds); the Eloquent concrete migrates + seeds a matching baseline.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aLocalIdBatchCreatesAndCrossReferencesInOrder(): void
    {
        // op0 creates an artist under a local id; op1 creates an album that references that
        // artist by `lid` — resolved to the just-assigned id by the shared registry.
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'lid' => 'new-artist', 'attributes' => ['name' => 'Aphex Twin', 'slug' => 'aphex-twin']]],
            ['op' => 'add', 'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Selected Ambient Works', 'status' => 'released', 'releasedAt' => '1992-11-09T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'lid' => 'new-artist']]],
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'atomic:results')
            ->assertJsonPath('atomic:results.0.data.type', 'artists')
            ->assertJsonPath('atomic:results.0.data.attributes.name', 'Aphex Twin')
            ->assertJsonPath('atomic:results.1.data.type', 'albums')
            ->assertJsonPath('atomic:results.1.data.attributes.title', 'Selected Ambient Works');

        $artistId = $response->json('atomic:results.0.data.id');
        $albumId = $response->json('atomic:results.1.data.id');
        $this->assertIsString($artistId);
        $this->assertIsString($albumId);

        // The batch committed: a follow-up read sees the created album linked to the created
        // artist (the lid resolved to the real, durable id) — identical on both providers.
        $this->readJsonApi('/api/albums/' . $albumId)
            ->assertOk()
            ->assertJsonPath('data.relationships.artist.data.id', $artistId);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aFailingOperationRollsBackTheWholeBatch(): void
    {
        $before = $this->readJsonApi('/api/artists')->json('data');
        $this->assertIsArray($before);
        $baseline = \count($before);

        // op0 adds an artist; op1 removes a non-existent album (a 404). The whole batch rolls
        // back at op1, so the op0 artist is NOT persisted.
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Ghost', 'slug' => 'ghost']]],
            ['op' => 'remove', 'ref' => ['type' => 'albums', 'id' => '999']],
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('errors.0.status', '404');

        // The failure is reported at the failing operation's index (op1).
        $pointer = $response->json('errors.0.source.pointer');
        $this->assertIsString($pointer);
        $this->assertStringStartsWith('/atomic:operations/1', $pointer);

        // Rolled back on BOTH providers: the artist count is unchanged.
        $after = $this->readJsonApi('/api/artists')->json('data');
        $this->assertIsArray($after);
        $this->assertCount($baseline, $after);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anOperationCanTargetItsEndpointByHref(): void
    {
        // An `href` target is resolved by matching it against the router (the same defaults a
        // direct call reads) — here a `remove` of the seeded album 1 by its self URL.
        $this->atomic([
            ['op' => 'remove', 'href' => '/api/albums/1'],
        ])->assertOk();

        // The remove committed: album 1 is gone on both providers.
        $this->readJsonApi('/api/albums/1')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anUpdateOperationMutatesAndPersists(): void
    {
        // op:update is a PATCH — its `data.id` is the target (not a rejected client-generated
        // create id), so the mutated attribute persists on both providers.
        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'albums', 'id' => '1'], 'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Kid A']]],
        ])
            ->assertOk()
            ->assertJsonPath('atomic:results.0.data.type', 'albums')
            ->assertJsonPath('atomic:results.0.data.id', '1')
            ->assertJsonPath('atomic:results.0.data.attributes.title', 'Kid A');

        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.title', 'Kid A');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aRelationshipTargetOperationSetsLinkageAfterCommit(): void
    {
        // op0 creates an artist under a local id; op1 targets album 1's `artist` relationship
        // (a `ref` carrying a relationship name, so its `data` IS linkage) and replaces it with
        // the just-created artist by `lid` — proving the relationship-target arm and the
        // linkage-lid rewrite path, committed durably.
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'lid' => 'fresh', 'attributes' => ['name' => 'Boards of Canada', 'slug' => 'boards-of-canada']]],
            ['op' => 'update', 'ref' => ['type' => 'albums', 'id' => '1', 'relationship' => 'artist'], 'data' => ['type' => 'artists', 'lid' => 'fresh']],
        ]);

        $response->assertOk();
        $artistId = $response->json('atomic:results.0.data.id');
        $this->assertIsString($artistId);

        $this->readJsonApi('/api/albums/1')
            ->assertOk()
            ->assertJsonPath('data.relationships.artist.data.id', $artistId);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anUnresolvableHrefIsRefused(): void
    {
        // An `href` that matches no JSON:API route cannot resolve a target — the batch is
        // refused in pre-flight (a 400) rather than silently no-op'ing.
        $this->atomic([
            ['op' => 'remove', 'href' => '/api/no-such-type/1'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'ATOMIC_HREF_UNRESOLVABLE');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function theBatchRequiresTheAtomicExtensionOnBothHeaders(): void
    {
        $operations = [['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'X', 'slug' => 'x']]]];

        // A plain Content-Type (no atomic ext) is a 415 — the extension's media-type contract.
        $this->atomic($operations, contentType: self::MEDIA_TYPE)
            ->assertStatus(415);

        // The atomic ext present on Content-Type but absent from Accept is a 406.
        $this->atomic($operations, accept: self::MEDIA_TYPE)
            ->assertStatus(406);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchTargetingAnUnregisteredTypeIsRefusedPreFlight(): void
    {
        // No persister is registered for `widgets`, so the pre-flight scan refuses the batch
        // before opening any transaction — a 404 (the routing-miss analogue inside a batch).
        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'widgets', 'attributes' => ['name' => 'nope']]],
        ])
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'ATOMIC_TARGET_TYPE_UNKNOWN');
    }

    /**
     * Issues an Atomic Operations batch against `POST /api/operations`, carrying the atomic
     * `ext` media-type parameter on both Content-Type and Accept by default (overridable to
     * exercise the negotiation failures).
     *
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations, ?string $contentType = null, ?string $accept = null): TestResponse
    {
        $body = (string) \json_encode(['atomic:operations' => $operations]);

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $accept ?? self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => $contentType ?? self::ATOMIC_MEDIA_TYPE,
        ], $body);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
