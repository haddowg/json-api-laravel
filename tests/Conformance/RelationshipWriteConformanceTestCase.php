<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-3b relationship-WRITE acceptance suite (the Laravel analogue of the bundle's
 * relationship-mutation + pivot-write conformance): the relationship-mutation endpoints
 * (`PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{rel}`), the relationships embedded in
 * whole-resource writes (`data.relationships`), pivot upserts with `meta.pivot`
 * merge-before-validate, the `cannotReplace`/`cannotAdd`/`cannotRemove` 403 family, and the
 * error families — every assertion run identically against the in-memory witness
 * ({@see InMemoryRelationshipWriteConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentRelationshipWriteConformanceTest}) over ONE seeded dataset (the shared
 * {@see \Workbench\App\Support\ConformanceFixtures}: album 1 owned by artist 1 (Radiohead),
 * playlist 1 owning ordered tracks 1/2/3/4, playlist 2 owning track 1, playlist 3 none), each
 * mutation re-fetched through the read endpoints to prove it persisted through the
 * {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface::mutateRelationship()}
 * seam — so a divergence localizes to one provider's persister execution.
 *
 * Two surfaces are provider-asymmetric by design and asserted through the
 * {@see providerRendersPivotMeta()} hook rather than here: the reference Eloquent provider
 * renders + stores the pivot `position`/`weight`/`addedAt` as each member's `meta.pivot`
 * (ADR 0008), while the in-memory witness stores none (the documented boundary — a pivot
 * column needs an association entity it cannot model), so a pivot write still changes the
 * membership on both but only Eloquent renders `meta.pivot`. Everything else — the membership
 * round-trips, the merge-before-validate 422s, the mutability 403s, the error families — is
 * identical on both providers.
 */
abstract class RelationshipWriteConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The workbench service provider that wires exactly ONE provider/persister pair
     * (in-memory or Eloquent) over the shared resources, seeded from the same fixtures.
     *
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * Whether the concrete's provider stores + renders the pivot `meta.pivot` (Eloquent
     * true, the in-memory witness false — the documented boundary, ADR 0008).
     */
    abstract protected function providerRendersPivotMeta(): bool;

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
     * Seeds the concrete's data layer. The in-memory concrete no-ops (the fixtures live
     * in the provider registration); the Eloquent concrete migrates + seeds.
     */
    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- to-one (owner-side BelongsTo) ----------------------------------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToOneReplacesItAndPersists(): void
    {
        // Album 1 is owned by artist 1 (Radiohead); replace with artist 3 (Massive Attack).
        $response = $this->writeRel('PATCH', '/api/albums/1/relationships/artist', ['type' => 'artists', 'id' => '3']);

        $response->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '3'], $response->json('data'));
        self::assertSame(['type' => 'artists', 'id' => '3'], $this->readRel('/api/albums/1/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToOneWithNullDataClearsItAndPersists(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', ['data' => null]);

        $response->assertOk();
        self::assertNull($response->json('data'));
        self::assertNull($this->readRel('/api/albums/1/relationships/artist')->json('data'));
    }

    // --- to-many (pivot belongsToMany) ----------------------------------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToManyReplacesTheWholeSetAndPersists(): void
    {
        // Playlist 2 owns [1]; replace its ordered-track set with [2, 3] carrying full pivot meta.
        $response = $this->writeManyRel('PATCH', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'weight' => 6]]],
            ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 6, 'weight' => 9]]],
        ]);

        $response->assertOk();
        self::assertSame(['2', '3'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function postingToAToManyAddsMembersIdempotentlyAndPersists(): void
    {
        // Playlist 2 owns [1]; add tracks 1 (already present — not duplicated) and 4: the
        // result is [1, 4]. Both members carry a valid pivot (track 1 an existing member, track
        // 4 a genuinely-new one needing its required position).
        $response = $this->writeManyRel('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['position' => 1, 'weight' => 1]]],
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'weight' => 8]]],
        ]);

        $response->assertOk();
        self::assertSame(['1', '4'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromAToManyRemovesMembersAndPersists(): void
    {
        // Playlist 1 owns [1,2,3,4]; remove track 1 (a remove carries no pivot meta).
        $response = $this->writeManyRel('DELETE', '/api/playlists/1/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '1'],
        ]);

        $response->assertOk();
        self::assertSame(['2', '3', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/orderedTracks')));
    }

    // --- pivot merge-before-validate (422 before persist, never a DB 500) -------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGenuinelyNewMemberMissingTheRequiredPivotPositionIs422(): void
    {
        // Track 4 is not in playlist 2, so it is a NEW row — its required `position` is absent,
        // a 422 before persist (never a DB NOT-NULL 500). Both providers treat it as new (the
        // witness stores no pivot, so every member is create-context).
        $response = $this->writeManyRel('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['weight' => 3]]],
        ]);

        $response->assertStatus(422);
        self::assertSame('VALIDATION_FAILED', $response->json('errors.0.code'));
        self::assertSame('/data/0/meta/pivot/position', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotWeightBelowThePositionIs422(): void
    {
        // The cross-pivot-field rule `weight >= position` rejects an inverted pair over the
        // merged meta — both fields present in the incoming meta, so it fires on both providers.
        $response = $this->writeManyRel('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 5, 'weight' => 2]]],
        ]);

        $response->assertStatus(422);
        self::assertSame('/data/0/meta/pivot/weight', $response->json('errors.0.source.pointer'));
    }

    // --- meta.pivot render shapes (provider-asymmetric via the hook) -----------

    #[Test]
    #[Group('spec:fetching')]
    public function theMutationEchoRendersPivotMetaOnlyWhenTheProviderStoresIt(): void
    {
        // A pivot mutation's 200 linkage echo carries the just-written pivot values on the
        // reference provider; the in-memory witness stores none, so its echo carries no
        // `meta.pivot` (ADR 0008 — the dual-provider assertion is Eloquent-present /
        // in-memory-absent).
        $response = $this->writeManyRel('PATCH', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'weight' => 6]]],
        ]);
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '2');

        if ($this->providerRendersPivotMeta()) {
            self::assertNotNull($pivot);
            self::assertSame(5, $pivot['position'] ?? null);
            self::assertSame(6, $pivot['weight'] ?? null);
        } else {
            self::assertNull($pivot);
        }
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelatedEndpointRendersPivotMetaOnlyWhenTheProviderStoresIt(): void
    {
        // Playlist 1's seeded pivot: track 1 at position 1 / weight 1 / added_at 2024-01-01.
        $response = $this->readRel('/api/playlists/1/orderedTracks');
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '1');

        if ($this->providerRendersPivotMeta()) {
            self::assertNotNull($pivot);
            self::assertSame(1, $pivot['position'] ?? null);
            self::assertSame(1, $pivot['weight'] ?? null);
            self::assertSame('2024-01-01 00:00:00', $pivot['addedAt'] ?? null);
        } else {
            self::assertNull($pivot);
        }
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelationshipEndpointRendersPivotMetaOnlyWhenTheProviderStoresIt(): void
    {
        $response = $this->readRel('/api/playlists/1/relationships/orderedTracks');
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $pivot = $this->pivotOf($data, '2');

        if ($this->providerRendersPivotMeta()) {
            self::assertNotNull($pivot);
            self::assertSame(2, $pivot['position'] ?? null);
            self::assertSame(2, $pivot['weight'] ?? null);
        } else {
            self::assertNull($pivot);
        }
    }

    // --- cannotReplace / cannotAdd / cannotRemove (403 family) -----------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingACannotReplaceRelationIsForbidden(): void
    {
        $response = $this->writeManyRel('PATCH', '/api/playlists/1/relationships/lockedTracks', [['type' => 'tracks', 'id' => '2']]);

        $response->assertStatus(403);
        self::assertSame('FULL_REPLACEMENT_PROHIBITED', $response->json('errors.0.code'));
        // The forbidden mutation did not persist: playlist 1 still owns [1,2,3,4].
        self::assertSame(['1', '2', '3', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/orderedTracks')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function postingToACannotAddRelationIsForbidden(): void
    {
        $response = $this->writeManyRel('POST', '/api/playlists/1/relationships/lockedTracks', [['type' => 'tracks', 'id' => '5']]);

        $response->assertStatus(403);
        self::assertSame('ADDITION_PROHIBITED', $response->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromACannotRemoveRelationIsForbidden(): void
    {
        $response = $this->writeManyRel('DELETE', '/api/playlists/1/relationships/lockedTracks', [['type' => 'tracks', 'id' => '1']]);

        $response->assertStatus(403);
        self::assertSame('REMOVAL_PROHIBITED', $response->json('errors.0.code'));
    }

    // --- error families (404 / 409 / 400) --------------------------------------

    #[Test]
    #[Group('spec:errors')]
    public function mutatingAnUnknownRelationshipIs404(): void
    {
        $this->writeManyRel('PATCH', '/api/playlists/1/relationships/nonsense', [['type' => 'tracks', 'id' => '1']])
            ->assertStatus(404);
    }

    #[Test]
    #[Group('spec:errors')]
    public function mutatingARelationshipOnAMissingParentIs404(): void
    {
        $this->writeManyRel('PATCH', '/api/playlists/9999/relationships/orderedTracks', [['type' => 'tracks', 'id' => '1']])
            ->assertStatus(404);
    }

    #[Test]
    #[Group('spec:errors')]
    public function aLinkageTypeConflictIs409(): void
    {
        // The album `artist` to-one accepts only `artists`; a `genres` linkage is a 409.
        $response = $this->writeRel('PATCH', '/api/albums/1/relationships/artist', ['type' => 'genres', 'id' => 'trip-hop']);

        $response->assertStatus(409);
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $response->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function postingToAToOneIsACardinalityError(): void
    {
        $response = $this->writeRel('POST', '/api/albums/1/relationships/artist', ['type' => 'artists', 'id' => '2']);

        $response->assertStatus(400);
        self::assertSame('RELATIONSHIP_TYPE_INAPPROPRIATE', $response->json('errors.0.code'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function deletingFromAToOneIsACardinalityError(): void
    {
        $this->writeRel('DELETE', '/api/albums/1/relationships/artist', ['type' => 'artists', 'id' => '2'])
            ->assertStatus(400);
    }

    // --- linkage `lid` on a relationship endpoint (400 LOCAL_ID_NOT_SUPPORTED) --

    #[Test]
    #[Group('spec:updating-relationships')]
    #[Group('spec:errors')]
    public function aLocalIdInARelationshipEndpointLinkageIs400(): void
    {
        // A relationship-endpoint linkage carrying a `lid` (a local id) is a 400
        // LOCAL_ID_NOT_SUPPORTED at /data/lid — local ids are an atomic-operations concept, not
        // a standalone-request one (bundle parity).
        $response = $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'lid' => 'temp']]);

        $response->assertStatus(400);
        self::assertSame('LOCAL_ID_NOT_SUPPORTED', $response->json('errors.0.code'));
        self::assertSame('/data/lid', $response->json('errors.0.source.pointer'));
    }

    // --- relationships embedded in whole-resource writes -----------------------

    #[Test]
    #[Group('spec:creating-resources')]
    #[Group('spec:relationships')]
    public function creatingAResourceWithAnEmbeddedRelationshipSetsItThroughTheSeam(): void
    {
        // POST an album carrying `data.relationships.artist`: core hydrates the attributes, and
        // the association is set through the persister seam (a typed entity never gets a scalar
        // id on its association property — the earlier 500).
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'A related album', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '3']]],
            ],
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        self::assertIsString($id);

        self::assertSame(['type' => 'artists', 'id' => '3'], $this->readRel('/api/albums/' . $id . '/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    #[Group('spec:errors')]
    public function aLocalIdInAnEmbeddedLinkageIs400(): void
    {
        // An embedded linkage carrying a `lid` is a 400 LOCAL_ID_NOT_SUPPORTED at the embedded
        // pointer /data/relationships/artist/data/lid (bundle parity).
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'x', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'lid' => 'temp']]],
            ],
        ]);

        $response->assertStatus(400);
        self::assertSame('LOCAL_ID_NOT_SUPPORTED', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/artist/data/lid', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    #[Group('spec:relationships')]
    public function patchingAResourceReplacesAnEmbeddedRelationshipThroughTheSeam(): void
    {
        // A whole-resource PATCH carrying `data.relationships.artist` replaces the owner
        // through the same seam as the relationship endpoint (not core's scalar-id hydration).
        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['title' => 'A retitled, re-linked album'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '2']]],
            ],
        ]);

        $response->assertOk();
        self::assertSame('A retitled, re-linked album', $response->json('data.attributes.title'));
        self::assertSame(['type' => 'artists', 'id' => '2'], $this->readRel('/api/albums/1/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    #[Group('spec:relationships')]
    public function patchingAResourceWithoutRelationshipsLeavesThemUntouched(): void
    {
        // A PATCH supplying no `data.relationships` must not disturb the existing association:
        // album 1 keeps artist 1.
        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Only the title changes']],
        ]);

        $response->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '1'], $this->readRel('/api/albums/1/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    #[Group('spec:relationships')]
    public function patchingAResourceReplacesAnEmbeddedPivotToManyThroughTheSeam(): void
    {
        // A whole-resource PATCH of playlist 2 embedding its `orderedTracks` pivot to-many
        // replaces the membership through the seam (the merge-before-validate pass runs off the
        // loaded parent's stored pivot). Playlist 2 owns [1] -> [2, 3].
        $response = $this->writeJsonApi('PATCH', '/api/playlists/2', [
            'data' => [
                'type' => 'playlists',
                'id' => '2',
                'relationships' => ['orderedTracks' => ['data' => [
                    ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'weight' => 6]]],
                    ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 6, 'weight' => 9]]],
                ]]],
            ],
        ]);

        $response->assertOk();
        self::assertSame(['2', '3'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
    }

    // --- bare belongsToMany (no pivot columns) — independent of the pivot join --

    #[Test]
    #[Group('spec:updating-relationships')]
    public function mutatingTheBareToManyRoundTripsAndLeavesThePivotMembershipUntouched(): void
    {
        // Playlist 1 owns [1,2,3,4] on BOTH the bare `tracks` join and the pivot `orderedTracks`.
        // An id-only bare-join mutation must succeed on both providers — never the NOT-NULL
        // `position` 500 a shared join would raise on the Eloquent `sync()` — and must NOT disturb
        // the pivot membership (separate join tables on Eloquent, independent lists on the
        // witness), so the two relations diverge under mutation on neither provider.
        $this->writeManyRel('PATCH', '/api/playlists/1/relationships/tracks', [
            ['type' => 'tracks', 'id' => '2'],
            ['type' => 'tracks', 'id' => '4'],
        ])->assertOk();
        self::assertSame(['2', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/tracks')));

        // The pivot membership is untouched by the bare-join mutation.
        self::assertSame(['1', '2', '3', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/orderedTracks')));

        // POST adds idempotently, DELETE removes — all id-only on the bare join.
        $this->writeManyRel('POST', '/api/playlists/1/relationships/tracks', [['type' => 'tracks', 'id' => '1']])->assertOk();
        self::assertSame(['1', '2', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/tracks')));

        $this->writeManyRel('DELETE', '/api/playlists/1/relationships/tracks', [['type' => 'tracks', 'id' => '2']])->assertOk();
        self::assertSame(['1', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/tracks')));
    }

    // --- embedded linkage pointers locate the relationship, not the endpoint ---

    #[Test]
    #[Group('spec:updating-resources')]
    #[Group('spec:errors')]
    public function anEmbeddedToManyTypeConflictPointsAtTheRelationshipLinkage(): void
    {
        // A wrong-typed EMBEDDED to-many member 409s at the relationship's own linkage pointer
        // `/data/relationships/orderedTracks/data/0/type` — not the relationship-endpoint
        // `/data/0/type`, and not the resource's own (correct) `/data/type`.
        $response = $this->writeJsonApi('PATCH', '/api/playlists/2', [
            'data' => [
                'type' => 'playlists',
                'id' => '2',
                'relationships' => ['orderedTracks' => ['data' => [
                    ['type' => 'genres', 'id' => 'trip-hop'],
                ]]],
            ],
        ]);

        $response->assertStatus(409);
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/orderedTracks/data/0/type', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    #[Group('spec:errors')]
    public function anEmbeddedPivotViolationPointsAtTheRelationshipLinkageMeta(): void
    {
        // A genuinely-new embedded member (track 4, not in playlist 2) missing its required pivot
        // `position` is a 422 at the EMBEDDED linkage meta pointer
        // `/data/relationships/orderedTracks/data/0/meta/pivot/position` — not the
        // relationship-endpoint `/data/0/meta/pivot/position`, which does not exist in a
        // whole-resource body.
        $response = $this->writeJsonApi('PATCH', '/api/playlists/2', [
            'data' => [
                'type' => 'playlists',
                'id' => '2',
                'relationships' => ['orderedTracks' => ['data' => [
                    ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['weight' => 3]]],
                ]]],
            ],
        ]);

        $response->assertStatus(422);
        self::assertSame('VALIDATION_FAILED', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/orderedTracks/data/0/meta/pivot/position', $response->json('errors.0.source.pointer'));
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * PATCH/POST/DELETE a to-one relationship endpoint with a single linkage identifier.
     *
     * @param array{type: string, id: string} $identifier
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeRel(string $method, string $uri, array $identifier): TestResponse
    {
        return $this->writeJsonApi($method, $uri, ['data' => $identifier]);
    }

    /**
     * PATCH/POST/DELETE a to-many relationship endpoint with a list of linkage identifiers.
     *
     * @param list<array<string, mixed>> $identifiers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeManyRel(string $method, string $uri, array $identifiers): TestResponse
    {
        return $this->writeJsonApi($method, $uri, ['data' => $identifiers]);
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
    protected function readRel(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * The sorted linkage ids of a to-many relationship / related document.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function linkageIds(TestResponse $response): array
    {
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        $ids = \array_map(static fn(array $row): string => $row['id'], $data);
        \sort($ids);

        return $ids;
    }

    /**
     * The `meta.pivot` map of the member with the given id in a related/linkage document, or
     * null when the member is absent / carries no pivot meta.
     *
     * @param list<array<string, mixed>> $data
     *
     * @return array<array-key, mixed>|null
     */
    protected function pivotOf(array $data, string $id): ?array
    {
        foreach ($data as $member) {
            if (($member['id'] ?? null) !== $id) {
                continue;
            }
            $meta = $member['meta'] ?? null;
            $pivot = \is_array($meta) ? ($meta['pivot'] ?? null) : null;

            return \is_array($pivot) ? $pivot : null;
        }

        return null;
    }
}
