<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The dual-provider relationship-MUTATION contract (Phase 3b): `PATCH`/`POST`/`DELETE` on
 * `/{type}/{id}/relationships/{rel}`, asserted identically against the Eloquent reference and
 * the in-memory witness (the concrete subclasses supply the wiring + like-linked seed data).
 * It covers every verb × cardinality (to-one incl. null-clear, to-many replace/add/remove, a
 * polymorphic to-one, and an inverse-FK HasMany), the `cannotReplace/cannotAdd/cannotRemove`
 * 403 family, the 404s (unknown relation / missing parent), the 409 linkage-type conflict,
 * the 400 cardinality error, and the policy gate (default update-on-parent + the per-relation
 * ability override, PLAN decision 7) — reading persistence back through the relationship
 * endpoints so load-state after a mutation is consistent on both providers.
 *
 * The seed graph (identical on both providers): authors `1` Ada / `2` Grace; tags `1` php /
 * `2` json / `3` rust; posts `1` Hello (author 1, feature the json tag, tags [1,2]), `2` World
 * (author 2, feature the Ada author, tags [1]), `3` Empty (no relations).
 *
 * @internal
 */
#[CoversNothing]
abstract class RelationshipMutationTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    // --- to-one (owner-side BelongsTo) ----------------------------------------

    public function test_patch_to_one_replaces_the_reference(): void
    {
        $response = $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2']);

        $response->assertOk();
        self::assertSame(['type' => 'authors', 'id' => '2'], $response->json('data'));
        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/1/relationships/author')->json('data'));
    }

    public function test_patch_to_one_null_clears_the_reference(): void
    {
        $response = $this->patchNull('/api/posts/1/relationships/author');

        $response->assertOk();
        self::assertNull($response->json('data'));
        self::assertNull($this->readRel('/api/posts/1/relationships/author')->json('data'));
    }

    // --- to-many (join-table BelongsToMany) -----------------------------------

    public function test_patch_to_many_replaces_the_whole_set(): void
    {
        $response = $this->writeManyRel('PATCH', '/api/posts/1/relationships/tags', [['type' => 'tags', 'id' => '3']]);

        $response->assertOk();
        self::assertSame(['3'], $this->linkageIds($response));
        self::assertSame(['3'], $this->linkageIds($this->readRel('/api/posts/1/relationships/tags')));
    }

    public function test_post_to_many_adds_members_idempotently(): void
    {
        $response = $this->writeManyRel('POST', '/api/posts/1/relationships/tags', [
            ['type' => 'tags', 'id' => '1'], // already present — not duplicated
            ['type' => 'tags', 'id' => '3'],
        ]);

        $response->assertOk();
        self::assertSame(['1', '2', '3'], $this->linkageIds($this->readRel('/api/posts/1/relationships/tags')));
    }

    public function test_delete_to_many_removes_members(): void
    {
        $response = $this->writeManyRel('DELETE', '/api/posts/1/relationships/tags', [['type' => 'tags', 'id' => '1']]);

        $response->assertOk();
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/posts/1/relationships/tags')));
    }

    // --- to-many (inverse-FK HasMany) -----------------------------------------

    public function test_patch_has_many_reparents_the_inverse_set(): void
    {
        // Author 1 owns post 1. Replace its posts with [post 2] — post 2 is re-parented to
        // author 1 (an FK-move on Eloquent, a parent-list set on the witness); the parent side
        // reads back the new set identically on both providers.
        $response = $this->writeManyRel('PATCH', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']]);

        $response->assertOk();
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
    }

    // --- polymorphic to-one (MorphTo) -----------------------------------------

    public function test_patch_morph_to_one_replaces_across_types(): void
    {
        // post 1's feature is the json tag; replace it with the Ada author (a different type
        // through the one polymorphic to-one).
        $toAuthor = $this->writeRel('PATCH', '/api/posts/1/relationships/feature', ['type' => 'authors', 'id' => '1']);
        $toAuthor->assertOk();
        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/feature')->json('data'));

        $toTag = $this->writeRel('PATCH', '/api/posts/1/relationships/feature', ['type' => 'tags', 'id' => '3']);
        $toTag->assertOk();
        self::assertSame(['type' => 'tags', 'id' => '3'], $this->readRel('/api/posts/1/relationships/feature')->json('data'));
    }

    public function test_patch_morph_to_one_null_clears_the_reference(): void
    {
        $response = $this->patchNull('/api/posts/1/relationships/feature');

        $response->assertOk();
        self::assertNull($this->readRel('/api/posts/1/relationships/feature')->json('data'));
    }

    // --- 403 mutability flags (cannotReplace / cannotAdd / cannotRemove) -------

    public function test_cannot_replace_a_prohibited_to_one_is_forbidden(): void
    {
        $response = $this->writeRel('PATCH', '/api/posts/1/relationships/sponsor', ['type' => 'authors', 'id' => '2']);

        $response->assertStatus(403);
        self::assertSame('FULL_REPLACEMENT_PROHIBITED', $response->json('errors.0.code'));
    }

    public function test_cannot_remove_a_prohibited_to_one_is_forbidden(): void
    {
        $response = $this->patchNull('/api/posts/1/relationships/sponsor');

        $response->assertStatus(403);
        self::assertSame('REMOVAL_PROHIBITED', $response->json('errors.0.code'));
    }

    public function test_cannot_replace_a_prohibited_to_many_is_forbidden(): void
    {
        $this->writeManyRel('PATCH', '/api/posts/1/relationships/pinnedTags', [['type' => 'tags', 'id' => '1']])
            ->assertStatus(403);
    }

    public function test_cannot_add_to_a_prohibited_to_many_is_forbidden(): void
    {
        $response = $this->writeManyRel('POST', '/api/posts/1/relationships/pinnedTags', [['type' => 'tags', 'id' => '1']]);

        $response->assertStatus(403);
        self::assertSame('ADDITION_PROHIBITED', $response->json('errors.0.code'));
    }

    public function test_cannot_remove_from_a_prohibited_to_many_is_forbidden(): void
    {
        $this->writeManyRel('DELETE', '/api/posts/1/relationships/pinnedTags', [['type' => 'tags', 'id' => '1']])
            ->assertStatus(403);
    }

    // --- 404 / 409 / 400 ------------------------------------------------------

    public function test_an_unknown_relationship_is_404(): void
    {
        $this->writeRel('PATCH', '/api/posts/1/relationships/nonsense', ['type' => 'authors', 'id' => '1'])
            ->assertStatus(404);
    }

    public function test_a_missing_parent_is_404(): void
    {
        $this->writeRel('PATCH', '/api/posts/999/relationships/author', ['type' => 'authors', 'id' => '1'])
            ->assertStatus(404);
    }

    public function test_a_linkage_type_conflict_is_409(): void
    {
        $response = $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'tags', 'id' => '1']);

        $response->assertStatus(409);
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $response->json('errors.0.code'));
    }

    public function test_posting_to_a_to_one_is_a_cardinality_error(): void
    {
        $response = $this->writeRel('POST', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2']);

        $response->assertStatus(400);
        self::assertSame('RELATIONSHIP_TYPE_INAPPROPRIATE', $response->json('errors.0.code'));
    }

    public function test_deleting_from_a_to_one_is_a_cardinality_error(): void
    {
        $this->writeRel('DELETE', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2'])
            ->assertStatus(400);
    }

    // --- relationships embedded in a whole-resource create (ADR 0009) ---------

    public function test_create_with_embedded_to_one_and_to_many_sets_both_through_the_seam(): void
    {
        // POST /api/posts embedding an owner-side to-one (`author`, a BelongsTo applied inline
        // before the insert — its FK on the parent row) AND a join to-many (`tags`, a
        // belongsToMany DEFERRED to after the parent is keyed — the Eloquent join insert needs
        // the parent PK; ADR 0009). The create + the deferred apply commit as ONE transaction, so
        // both associations read back on both providers — the headline ADR-0009 path the suite
        // previously exercised only for the embedded to-one.
        $response = $this->writeJsonApi('POST', '/api/posts', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'Fresh'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => '2']],
                    'tags' => ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '3']]],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        self::assertIsString($id);

        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/' . $id . '/relationships/author')->json('data'));
        self::assertSame(['1', '3'], $this->linkageIds($this->readRel('/api/posts/' . $id . '/relationships/tags')));
    }

    public function test_an_embedded_linkage_type_conflict_points_at_the_relationship(): void
    {
        // A wrong-typed EMBEDDED linkage 409s at the relationship's own linkage pointer
        // `/data/relationships/<rel>/data/type` — NOT the relationship-endpoint `/data/type`,
        // which in a whole-resource body is the resource's own (correct) type member.
        $response = $this->writeJsonApi('POST', '/api/posts', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'x'],
                'relationships' => ['author' => ['data' => ['type' => 'tags', 'id' => '1']]],
            ],
        ]);

        $response->assertStatus(409);
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/author/data/type', $response->json('errors.0.source.pointer'));
    }

    // --- authorization (PLAN decision 7) --------------------------------------

    public function test_the_default_update_policy_gates_a_mutation(): void
    {
        Gate::define('update', static fn(?Authenticatable $user): bool => false);

        $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2'])
            ->assertStatus(403);
    }

    public function test_a_per_relation_ability_override_denies_a_mutation(): void
    {
        // `moderator` declares security(mutate: 'curate'); a denied `curate` ability 403s it
        // while the default-update relations stay inert.
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->writeRel('PATCH', '/api/posts/1/relationships/moderator', ['type' => 'authors', 'id' => '2'])
            ->assertStatus(403);
    }

    public function test_a_per_relation_ability_override_allows_a_mutation(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => true);

        $response = $this->writeRel('PATCH', '/api/posts/1/relationships/moderator', ['type' => 'authors', 'id' => '2']);

        $response->assertOk();
        self::assertSame(['type' => 'authors', 'id' => '2'], $response->json('data'));
    }

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
     * PATCH a to-one relationship endpoint with a null (clearing) linkage.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function patchNull(string $uri): TestResponse
    {
        return $this->writeJsonApi('PATCH', $uri, ['data' => null]);
    }

    /**
     * PATCH/POST/DELETE a to-many relationship endpoint with a list of linkage identifiers.
     *
     * @param list<array{type: string, id: string}> $identifiers
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
     * The sorted linkage ids of a to-many relationship document.
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
}
