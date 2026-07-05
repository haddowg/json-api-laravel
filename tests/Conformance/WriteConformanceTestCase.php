<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The write half of the dual-provider conformance contract: identical
 * create/update/delete assertions run against the in-memory witness
 * ({@see InMemoryWriteConformanceTest}) and the reference Eloquent provider over SQLite
 * ({@see EloquentWriteConformanceTest}), so a failure on one provider but not the other
 * localizes to that persister's execution — the Laravel port of the bundle's
 * `WriteConformanceTestCase`. Each concrete differs only in the wiring service provider
 * (and the Eloquent one's migrate + seed).
 *
 * The dataset is the minimal 2-row {@see \Workbench\App\Support\Fixtures} both feature
 * wirings seed, so a created `albums` row is store-provided id `3` on BOTH providers (the
 * in-memory sequence and the SQLite auto-increment both continue past the two seeds). The
 * base URI is pinned to the request origin + the `api` route prefix, so the `Location`
 * (and the created resource's equal `links.self`, core ADR 0054) resolves to the route a
 * client GETs.
 *
 * Coverage: create (server- and client-generated ids per strategy, `201` + `Location`
 * correctness, document echo, re-fetch), update (sparse — untouched fields survive — plus
 * explicit-`null` clearing), delete (`204` then `404`), the conflict/negotiation family
 * core owns (`409` body-type mismatch, forbidden-vs-allowed client id `403`/round-trip,
 * standalone `lid` `400`, `415`/`406` write content negotiation), and unknown type/id
 * `404`s. Document-semantic `422`s live in {@see ValidationConformanceTestCase}; the
 * relationship writes are Phase 3 and stay VISIBLE here as explicit skip markers.
 *
 * A **duplicate client-generated id** is now a `409` on both providers (the persisters
 * enforce core's `ClientGeneratedIdAlreadyExists`, closing the earlier
 * overwrite-vs-`500` divergence — see {@see aDuplicateClientGeneratedIdReturns409}). The
 * two conflict-family cells once FLAGGED as core gaps are now RESOLVED (the core changes
 * landed): a **PATCH body-id/URL-id mismatch** is a `409` RESOURCE_ID_CONFLICT
 * ({@see aPatchBodyIdMismatchingTheUrlIsA409Conflict}), and an unparseable date reaching
 * core's DateTime cast is a typed `422` ATTRIBUTE_VALUE_INVALID
 * ({@see anUnparseableDateReachingCoreHydrationIsATyped422}).
 */
abstract class WriteConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The workbench service provider that wires exactly ONE provider/persister pair
     * (in-memory or Eloquent) over the shared resources, seeded from the same fixtures.
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
        // Pin the base URI to the request origin + the `api` prefix so the created
        // resource's Location resolves to the route a client GETs (and equals its
        // links.self, core ADR 0054) rather than the prefix-less request origin.
        $config->set('jsonapi.base_uri', 'http://localhost/api');
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

    #[Test]
    #[Group('spec:crud')]
    public function creatingAResourceReturns201WithLocationAndTheCreatedDocument(): void
    {
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'A Brand New Album',
                    'status' => 'released',
                    'releasedAt' => '2020-02-02T00:00:00+00:00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('data.type', 'albums');

        // The id is store-provided: the create omits `data.id` and the store assigns the
        // next sequential id past the two seeded rows — a predictable `3` on BOTH
        // providers (the in-memory sequence and the SQLite auto-increment both continue
        // past the seed), which round-trips through the response and a re-fetch.
        $response->assertJsonPath('data.id', '3');

        $location = 'http://localhost/api/albums/3';
        $response->assertHeader('Location', $location);
        // The created resource carries its convention self link, equal to the Location
        // (the persister has assigned the id by render time — core ADR 0054).
        $response->assertJsonPath('data.links.self', $location);

        $response->assertJsonPath('data.attributes.title', 'A Brand New Album');
        $response->assertJsonPath('data.attributes.status', 'released');

        // The created resource is persisted: a follow-up read returns it.
        $this->readJsonApi('/api/albums/3')
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'A Brand New Album');
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAResourceReturns200AndAppliesOnlyTheSuppliedAttributes(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['title' => 'An Edited Title'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.title', 'An Edited Title');
        // A partial update leaves the unsupplied attributes untouched (album 1 is
        // `released` in the fixtures).
        $response->assertJsonPath('data.attributes.status', 'released');

        // The change is persisted.
        $this->readJsonApi('/api/albums/1')
            ->assertJsonPath('data.attributes.title', 'An Edited Title')
            ->assertJsonPath('data.attributes.status', 'released');
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUndeclaredAttributeInAWriteBodyIsSilentlyIgnored(): void
    {
        // Allow-list hydration: an attribute the resource did not declare (the classic
        // mass-assignment over-post) is dropped — never written, and the declared-only
        // resource the engine renders never surfaces it.
        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => [
                    'title' => 'Edited Via Allow-List',
                    'isAdmin' => true,
                    'undeclared' => 'nope',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.title', 'Edited Via Allow-List');

        /** @var array<string, mixed> $attributes */
        $attributes = $response->json('data.attributes');
        self::assertArrayNotHasKey('isAdmin', $attributes);
        self::assertArrayNotHasKey('undeclared', $attributes);
    }

    #[Test]
    #[Group('spec:crud')]
    public function deletingAResourceReturns204AndThenItIsGone(): void
    {
        $response = $this->deleteJsonApi('/api/albums/1');

        $response->assertStatus(204);
        $response->assertNoContent();

        $this->readJsonApi('/api/albums/1')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAMissingResourceReturns404(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/albums/404', [
            'data' => [
                'type' => 'albums',
                'id' => '404',
                'attributes' => ['title' => 'Does Not Matter'],
            ],
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    #[Group('spec:crud')]
    public function deletingAMissingResourceReturns404(): void
    {
        $this->deleteJsonApi('/api/albums/404')->assertStatus(404);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aResourceTypeConflictInTheBodyReturns409(): void
    {
        // The body `type` must match the endpoint's type; core's hydrator rejects a
        // mismatch with a `409` before the persister runs — the store is untouched.
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'artists',
                'attributes' => ['title' => 'Wrong Type', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0.status', '409');

        // The mismatch aborted before persist: no new album was written.
        $this->readJsonApi('/api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:crud')]
    public function aClientGeneratedIdOnAForbiddenTypeReturns403(): void
    {
        // `albums` keeps the default id policy (client id forbidden), so a supplied
        // `data.id` is a `403` — the counterpart to the client-id `genres` type.
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'id' => '999',
                'attributes' => ['title' => 'Client Id', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
            ],
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:crud')]
    public function aClientGeneratedIdOnAnAllowingTypeRoundTrips(): void
    {
        // `genres` requires a client-generated id (its id is a natural key), so a
        // supplied `data.id` is honoured and round-trips to the `201` and a re-fetch.
        $response = $this->writeJsonApi('POST', '/api/genres', [
            'data' => [
                'type' => 'genres',
                'id' => 'ambient',
                'attributes' => ['name' => 'Ambient'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'genres');
        $response->assertJsonPath('data.id', 'ambient');
        $response->assertHeader('Location', 'http://localhost/api/genres/ambient');

        $this->readJsonApi('/api/genres/ambient')
            ->assertOk()
            ->assertJsonPath('data.attributes.name', 'Ambient');
    }

    #[Test]
    #[Group('spec:atomic-operations')]
    public function creatingWithALocalIdOnThePrimaryResourceReturns400(): void
    {
        // `lid` is an Atomic Operations member; a standalone create carrying one is a
        // `400` (not silently ignored), on both providers.
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'lid' => 'a1',
                'attributes' => ['title' => 'x', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.0.code', 'LOCAL_ID_NOT_SUPPORTED');
        $response->assertJsonPath('errors.0.source.pointer', '/data/lid');
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingWithAnExplicitNullClearsANullableAttribute(): void
    {
        // Distinct from an OMITTED attribute (left untouched, asserted above): an explicit
        // `null` on a nullable field CLEARS it. Album 1 carries a non-null `availableFrom`
        // in the fixtures; a PATCH sending `availableFrom: null` nulls that field — and only
        // it, the sibling `title` is untouched — identically on both providers.
        $before = $this->readJsonApi('/api/albums/1');
        $before->assertStatus(200);
        $availableFrom = $before->json('data.attributes.availableFrom');
        self::assertIsString($availableFrom);
        self::assertStringStartsWith('1997-05-21', $availableFrom);

        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['availableFrom' => null],
            ],
        ]);

        $response->assertStatus(200);
        self::assertNull($response->json('data.attributes.availableFrom'));
        $response->assertJsonPath('data.attributes.title', 'OK Computer');

        // The clear is persisted.
        $this->readJsonApi('/api/albums/1')
            ->assertStatus(200)
            ->assertJsonPath('data.attributes.availableFrom', null);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function creatingWithAParametrizedJsonApiContentTypeReturns415(): void
    {
        // Content negotiation on a write: the JSON:API media type carrying a media-type
        // parameter other than `ext`/`profile` (here a `charset`) is a `415` — core's
        // negotiate() rejects the `Content-Type` before the operation runs. A foreign media
        // type (e.g. `text/plain`) is NOT rejected by the spec's 415 rule, so the trigger is
        // specifically the parametrized JSON:API type. Identical on both providers.
        $response = $this->json('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => [
                'title' => 'X', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00',
            ]],
        ], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE . '; charset=utf-8']);

        $response->assertStatus(415);
        $response->assertJsonPath('errors.0.code', 'MEDIA_TYPE_UNSUPPORTED');
        $response->assertJsonPath('errors.0.source.parameter', 'content-type');

        // Rejected before persist: no new album was written.
        $this->readJsonApi('/api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function creatingWithAFullyParametrizedJsonApiAcceptReturns406(): void
    {
        // The Accept twin: a 406 is required only when EVERY JSON:API instance in `Accept`
        // is parametrized (here a lone `charset`-carrying instance), so no acceptable
        // representation remains. Identical on both providers.
        $response = $this->json('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => [
                'title' => 'X', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00',
            ]],
        ], ['Accept' => self::MEDIA_TYPE . '; charset=utf-8', 'CONTENT_TYPE' => self::MEDIA_TYPE]);

        $response->assertStatus(406);
        $response->assertJsonPath('errors.0.code', 'MEDIA_TYPE_UNACCEPTABLE');
        $response->assertJsonPath('errors.0.source.parameter', 'accept');

        $this->readJsonApi('/api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:crud')]
    public function endpointsForAnUnknownTypeReturn404(): void
    {
        // A type with no registered resource has no routes at all (the routes are literal
        // per-resource, no catch-all), so both a read and a write of it fall through to the
        // router's 404 — the unknown-TYPE counterpart to the unknown-ID 404s above.
        $this->readJsonApi('/api/widgets/1')->assertStatus(404);
        $this->writeJsonApi('POST', '/api/widgets', [
            'data' => ['type' => 'widgets', 'attributes' => ['title' => 'X']],
        ])->assertStatus(404);
    }

    // --- relationships in writes (Phase 3b — un-skipped as relations landed) ---

    #[Test]
    #[Group('spec:crud')]
    #[Group('spec:relationships')]
    public function embeddedRelationshipLinkageWritesSetTheAssociationThroughTheSeam(): void
    {
        // A whole-resource write carrying `data.relationships` sets the association through the
        // persister's relationship seam, not core's scalar-id hydration (which would assign a
        // string id to a typed association property). The album `artist` BelongsTo is the
        // owner-side to-one on both providers; the minimal fixtures own artists 1 (Radiohead)
        // and 2 (Portishead), album 1 -> artist 1.
        $create = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'A related album', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '1']]],
            ],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');
        self::assertIsString($id);
        self::assertSame(['type' => 'artists', 'id' => '1'], $this->readJsonApi('/api/albums/' . $id . '/relationships/artist')->json('data'));

        // A whole-resource PATCH replaces the association through the same seam.
        $patch = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '2']]]],
        ]);
        $patch->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '2'], $this->readJsonApi('/api/albums/1/relationships/artist')->json('data'));

        // A PATCH that supplies no `data.relationships` leaves the association untouched.
        $this->writeJsonApi('PATCH', '/api/albums/2', [
            'data' => ['type' => 'albums', 'id' => '2', 'attributes' => ['title' => 'Only the title changes']],
        ])->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '2'], $this->readJsonApi('/api/albums/2/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:crud')]
    #[Group('spec:relationships')]
    #[Group('spec:errors')]
    public function anEmbeddedLinkageLocalIdIs400(): void
    {
        // Ported from the bundle WriteConformanceTestCase::creatingWithALocalIdInEmbeddedLinkageReturns400:
        // an embedded linkage carrying a `lid` (a local id) is a 400 LOCAL_ID_NOT_SUPPORTED at
        // the embedded pointer /data/relationships/artist/data/lid — local ids are an
        // atomic-operations concept, not a standalone-request one.
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'x', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'lid' => 'temp']]],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.0.code', 'LOCAL_ID_NOT_SUPPORTED');
        $response->assertJsonPath('errors.0.source.pointer', '/data/relationships/artist/data/lid');
    }

    #[Test]
    #[Group('spec:relationships')]
    public function relationshipEndpointMutationsReplaceClearAndPersist(): void
    {
        // PATCH …/relationships/{rel} with a to-one linkage replaces it; `data: null` clears it;
        // both persist through the mutateRelationship seam (re-read through the read endpoint).
        $replace = $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'id' => '2']]);
        $replace->assertOk();
        self::assertSame(['type' => 'artists', 'id' => '2'], $replace->json('data'));
        self::assertSame(['type' => 'artists', 'id' => '2'], $this->readJsonApi('/api/albums/1/relationships/artist')->json('data'));

        $clear = $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', ['data' => null]);
        $clear->assertOk();
        self::assertNull($clear->json('data'));
        self::assertNull($this->readJsonApi('/api/albums/1/relationships/artist')->json('data'));
    }

    #[Test]
    #[Group('spec:relationships')]
    #[Group('spec:errors')]
    public function aRelationshipEndpointLinkageLocalIdIs400(): void
    {
        // Ported from the bundle WriteConformanceTestCase::mutatingARelationshipWithALocalIdLinkageReturns400:
        // a relationship-endpoint linkage carrying a `lid` is a 400 LOCAL_ID_NOT_SUPPORTED at
        // /data/lid.
        $response = $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'lid' => 'temp']]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.0.code', 'LOCAL_ID_NOT_SUPPORTED');
        $response->assertJsonPath('errors.0.source.pointer', '/data/lid');
    }

    #[Test]
    #[Group('spec:relationships')]
    #[Group('spec:errors')]
    public function postingOrDeletingAToOneRelationshipEndpointIsACardinalityError(): void
    {
        // A POST / DELETE to a to-one relationship endpoint is a 400 (a to-one has no
        // add/remove semantics).
        $this->writeJsonApi('POST', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'id' => '2']])
            ->assertStatus(400);
        $this->writeJsonApi('DELETE', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'id' => '2']])
            ->assertStatus(400);
    }

    // --- explicitly flagged core-owned gaps (kept visible, not deleted) --------

    #[Test]
    #[Group('spec:crud')]
    public function aDuplicateClientGeneratedIdReturns409(): void
    {
        // Re-POSTing an existing client-generated id (genre "trip-hop") is a `409`
        // CLIENT_GENERATED_ID_ALREADY_EXISTS at /data/id on BOTH providers: the persisters
        // enforce a pre-save existence check (in-memory store lookup / Eloquent whereKey
        // exists), closing the earlier overwrite-vs-500 divergence.
        $response = $this->writeJsonApi('POST', '/api/genres', [
            'data' => ['type' => 'genres', 'id' => 'trip-hop', 'attributes' => ['name' => 'Should Not Overwrite']],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0.status', '409');
        $response->assertJsonPath('errors.0.code', 'CLIENT_GENERATED_ID_ALREADY_EXISTS');
        $response->assertJsonPath('errors.0.source.pointer', '/data/id');

        // The existing resource is untouched: still two genres, and trip-hop keeps its
        // seeded name (not overwritten by the rejected create).
        $this->readJsonApi('/api/genres')->assertJsonCount(2, 'data');
        $this->readJsonApi('/api/genres/trip-hop')
            ->assertOk()
            ->assertJsonPath('data.attributes.name', 'Trip Hop');
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithoutARequiredClientGeneratedIdReturns403(): void
    {
        // `genres` requireClientId()s its id, so omitting `data.id` on create is a `403`
        // CLIENT_GENERATED_ID_REQUIRED at /data/id (core's create hydration enforces it) —
        // the third cell of the id-policy matrix, on both providers.
        $response = $this->writeJsonApi('POST', '/api/genres', [
            'data' => ['type' => 'genres', 'attributes' => ['name' => 'No Id Supplied']],
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('errors.0.status', '403');
        $response->assertJsonPath('errors.0.code', 'CLIENT_GENERATED_ID_REQUIRED');
        $response->assertJsonPath('errors.0.source.pointer', '/data/id');

        // Rejected before persist: still only the two seeded genres.
        $this->readJsonApi('/api/genres')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:crud')]
    public function anExplicitNullOnANonNullableAttributeReturns422(): void
    {
        // The non-nullable counterpart to updatingWithAnExplicitNullClearsANullableAttribute:
        // `status` is a non-nullable attribute with no typed value rule, so an explicit
        // `null` is a clean `422` at /data/attributes/status (the always-on bridge's NotNull
        // guard) — NOT a hydration 500 (a TypeError on the POPO / a NOT NULL QueryException
        // on Eloquent). Identical on both providers.
        $patch = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['status' => null]],
        ]);

        $patch->assertStatus(422);
        $patch->assertJsonPath('errors.0.status', '422');
        $patch->assertJsonPath('errors.0.source.pointer', '/data/attributes/status');

        // The rejected PATCH left the target unchanged (album 1 stays `released`).
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.status', 'released');

        // Create is the same: an explicit null on the non-nullable `status` is a 422, not a
        // 500 (the whole document is otherwise valid).
        $post = $this->writeJsonApi('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => [
                'title' => 'A Brand New Album', 'status' => null, 'releasedAt' => '2020-02-02T00:00:00+00:00',
            ]],
        ]);

        $post->assertStatus(422);
        $post->assertJsonPath('errors.0.source.pointer', '/data/attributes/status');

        // Rejected before persist: still the two seeded albums.
        $this->readJsonApi('/api/albums')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUnparseableDateReachingCoreHydrationIsATyped422(): void
    {
        // The core-owned change the earlier GAP flagged has LANDED (core ADR: an
        // uncoercible attribute value is a typed AttributeValueInvalid): core's DateTime
        // cast (haddowg\JsonApi\Resource\Field\DateTime::deserializeValue) now raises a
        // `422` ATTRIBUTE_VALUE_INVALID at /data/attributes/<name> on a calendar-garbage
        // string, instead of letting the raw `new \DateTimeImmutable($value)` \Exception
        // escape as an uncaught 500.
        //
        // The routed HTTP write surface never reaches this cast with garbage — the
        // always-on validation bridge (PLAN decision 6) guards every writable DateTime
        // field with a ParsableDate rule, a clean 422 BEFORE hydration (asserted
        // dual-provider over HTTP in ValidationConformanceTestCase). And the only path that
        // skips the bridge, a bare serializer/hydrator pair with no AbstractResource, is
        // discovered but routed fetch-only (RouteRegistrar::addSerializerRoutes emits GET
        // only), so no auto-registered write route reaches hydration without the bridge.
        // So this pins the underlying core cast the bridge
        // fronts by driving the exact field the fix touched — its typed error is the
        // ground truth both persisters inherit (it throws in core, before either runs).
        $field = DateTime::make('releasedAt');

        try {
            $field->hydrate(new \stdClass(), 'banana', [], $this->createStub(JsonApiRequestInterface::class), true);
            self::fail('Expected a calendar-garbage date to raise AttributeValueInvalid.');
        } catch (AttributeValueInvalid $e) {
            self::assertSame(422, $e->getStatusCode());
            $error = $e->getErrors()[0];
            self::assertSame('422', $error->status);
            self::assertSame('ATTRIBUTE_VALUE_INVALID', $error->code);
            self::assertSame('/data/attributes/releasedAt', $error->source?->pointer);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aPatchBodyIdMismatchingTheUrlIsA409Conflict(): void
    {
        // The core-owned change the earlier GAP flagged has LANDED: core now compares the
        // PATCH document `data.id` to the endpoint id (the id half of the type/id conflict
        // family). A mismatch is a `409` RESOURCE_ID_CONFLICT pointed at /data/id, thrown
        // by core BEFORE the handler runs — so it is identical on both providers and
        // nothing is persisted.
        $patch = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '999', 'attributes' => ['title' => 'Renamed']],
        ]);

        $patch->assertStatus(409);
        $patch->assertHeader('Content-Type', self::MEDIA_TYPE);
        $patch->assertJsonPath('errors.0.status', '409');
        $patch->assertJsonPath('errors.0.code', 'RESOURCE_ID_CONFLICT');
        $patch->assertJsonPath('errors.0.source.pointer', '/data/id');

        // Rejected before persist: album 1 keeps its seeded title.
        $this->readJsonApi('/api/albums/1')->assertJsonPath('data.attributes.title', 'OK Computer');
    }

    /**
     * POST/PATCH/DELETE a JSON:API document through the shipped {@see InteractsWithJsonApi}
     * kit, which negotiates the JSON:API media type on both the request `Content-Type` and
     * the `Accept` header. (Dogfoods the testing kit — PLAN decision 12.)
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
            'DELETE' => $request->delete($uri),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported JSON:API write method "%s".', $method)),
        };
    }

    /**
     * DELETE a resource, with no request body (a resource delete carries none).
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function deleteJsonApi(string $uri): TestResponse
    {
        return $this->jsonApi()->delete($uri);
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
}
