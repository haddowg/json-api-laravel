<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;

/**
 * The dual-provider relationship-MUTATION contract: `PATCH`/`POST`/`DELETE` on
 * `/{type}/{id}/relationships/{rel}`, asserted identically against the Eloquent reference and
 * the in-memory witness (the concrete subclasses supply the wiring + like-linked seed data).
 * It covers every verb × cardinality (to-one incl. null-clear, to-many replace/add/remove, a
 * polymorphic to-one, and an inverse-FK HasMany), the `cannotReplace/cannotAdd/cannotRemove`
 * 403 family, the 404s (unknown relation / missing parent), the 409 linkage-type conflict,
 * the 400 cardinality error, and the policy gate (default update-on-parent + the per-relation
 * ability override) — reading persistence back through the relationship
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

    /** @var array<string, list<string>> */
    private array $updatableIds = [];

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

    public function test_delete_has_many_leaves_another_parents_members_alone(): void
    {
        // Post 2 belongs to author 2, so removing it from author 1 changes nothing.
        $this->writeManyRel('DELETE', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']])
            ->assertOk();

        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/2/relationships/posts')));
        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/2/relationships/author')->json('data'));
    }

    public function test_adding_another_parents_member_needs_update_on_that_parent(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']])
            ->assertStatus(403);

        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/2/relationships/posts')));
    }

    public function test_replacing_with_another_parents_member_needs_update_on_that_parent(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        $this->writeManyRel('PATCH', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']])
            ->assertStatus(403);

        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/2/relationships/posts')));
    }

    public function test_an_embedded_update_with_another_parents_member_needs_update_on_that_parent(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        $this->writeJsonApi('PATCH', '/api/authors/1', [
            'data' => [
                'type' => 'authors',
                'id' => '1',
                'relationships' => ['posts' => ['data' => [['type' => 'posts', 'id' => '2']]]],
            ],
        ])->assertStatus(403);

        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/2/relationships/posts')));
    }

    public function test_an_embedded_create_with_another_parents_member_needs_update_on_that_parent(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        $this->writeJsonApi('POST', '/api/authors', [
            'data' => [
                'type' => 'authors',
                'attributes' => ['name' => 'Hedy'],
                'relationships' => ['posts' => ['data' => [['type' => 'posts', 'id' => '2']]]],
            ],
        ])->assertStatus(403);

        $this->readRel('/api/authors')->assertJsonCount(2, 'data');
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/authors/2/relationships/posts')));
    }

    public function test_adding_another_parents_member_with_update_on_that_parent_moves_it(): void
    {
        $this->allowUpdateOnlyOn('authors', '1', '2');

        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']])
            ->assertOk();

        self::assertSame(['1', '2'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
    }

    public function test_adding_another_parents_member_with_update_on_the_member_moves_it(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');
        $this->allowUpdateOnlyOn('posts', '2');

        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '2']])
            ->assertOk();

        self::assertSame(['1', '2'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
    }

    public function test_adding_an_unowned_member_needs_update_on_the_member(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        // Post 3 has no author, so only its own update ability can allow the move.
        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '3']])
            ->assertStatus(403);
        $this->writeManyRel('PATCH', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '1'], ['type' => 'posts', 'id' => '3']])
            ->assertStatus(403);

        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
    }

    public function test_adding_an_unowned_member_with_update_on_the_member_moves_it(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');
        $this->allowUpdateOnlyOn('posts', '3');

        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '3']])
            ->assertOk();

        self::assertSame(['1', '3'], $this->linkageIds($this->readRel('/api/authors/1/relationships/posts')));
    }

    public function test_re_adding_a_current_member_needs_no_other_update(): void
    {
        $this->allowUpdateOnlyOn('authors', '1');

        $this->writeManyRel('POST', '/api/authors/1/relationships/posts', [['type' => 'posts', 'id' => '1']])
            ->assertOk();
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

    // --- read-only relations ---------------------------------------------------

    public function test_a_read_only_to_one_refuses_replacement_and_removal(): void
    {
        $replace = $this->writeRel('PATCH', '/api/posts/1/relationships/editor', ['type' => 'authors', 'id' => '2']);
        $replace->assertStatus(403);
        self::assertSame('FULL_REPLACEMENT_PROHIBITED', $replace->json('errors.0.code'));

        $clear = $this->patchNull('/api/posts/1/relationships/editor');
        $clear->assertStatus(403);
        self::assertSame('REMOVAL_PROHIBITED', $clear->json('errors.0.code'));

        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/author')->json('data'));
    }

    public function test_a_read_only_to_many_refuses_every_mutation(): void
    {
        $codes = [
            'PATCH' => 'FULL_REPLACEMENT_PROHIBITED',
            'POST' => 'ADDITION_PROHIBITED',
            'DELETE' => 'REMOVAL_PROHIBITED',
        ];
        foreach ($codes as $method => $code) {
            $response = $this->writeManyRel($method, '/api/posts/1/relationships/frozenTags', [['type' => 'tags', 'id' => '3']]);
            $response->assertStatus(403);
            self::assertSame($code, $response->json('errors.0.code'), $method);
        }

        self::assertSame(['1', '2'], $this->linkageIds($this->readRel('/api/posts/1/relationships/tags')));
    }

    public function test_the_document_lists_no_mutations_for_a_read_only_relation(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        /** @var array{paths: array<string, array<string, mixed>>} $doc */
        $doc = $factory->forServer()->toArray();

        foreach (['editor', 'frozenTags'] as $name) {
            $operations = $doc['paths']['/posts/{id}/relationships/' . $name] ?? [];
            self::assertArrayHasKey('get', $operations);
            self::assertSame([], \array_intersect(['patch', 'post', 'delete'], \array_keys($operations)), $name);
        }
        self::assertArrayHasKey('patch', $doc['paths']['/posts/{id}/relationships/author'] ?? []);
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

    // --- relationships embedded in a whole-resource create ---------

    public function test_create_with_embedded_to_one_and_to_many_sets_both_through_the_seam(): void
    {
        // POST /api/posts embedding an owner-side to-one (`author`, a BelongsTo applied inline
        // before the insert — its FK on the parent row) AND a join to-many (`tags`, a
        // belongsToMany DEFERRED to after the parent is keyed — the Eloquent join insert needs
        // the parent PK). The create + the deferred apply commit as ONE transaction, so
        // both associations read back on both providers — the headline path the suite
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

    // --- authorization --------------------------------------

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

    public function test_a_per_relation_ability_override_nothing_defines_denies_a_mutation(): void
    {
        // No policy and no `Gate::define('curate')`: the declared ability denies rather
        // than being skipped, while the undeclared default-update relations stay inert.
        $this->writeRel('PATCH', '/api/posts/1/relationships/moderator', ['type' => 'authors', 'id' => '2'])
            ->assertStatus(403);
        $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2'])
            ->assertOk();
    }

    public function test_a_per_relation_ability_override_allows_a_mutation(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => true);

        $response = $this->writeRel('PATCH', '/api/posts/1/relationships/moderator', ['type' => 'authors', 'id' => '2']);

        $response->assertOk();
        self::assertSame(['type' => 'authors', 'id' => '2'], $response->json('data'));
    }

    public function test_an_embedded_create_honours_the_relation_mutate_ability(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->writeJsonApi('POST', '/api/posts', $this->postWithModerator(null, '2'))
            ->assertStatus(403);

        $this->readRel('/api/posts')->assertJsonCount(3, 'data');
    }

    public function test_an_embedded_update_honours_the_relation_mutate_ability(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->writeJsonApi('PATCH', '/api/posts/1', $this->postWithModerator('1', '2'))
            ->assertStatus(403);

        self::assertNull($this->readRel('/api/posts/1/relationships/moderator')->json('data'));
        self::assertSame('Hello', $this->readRel('/api/posts/1')->json('data.attributes.title'));
    }

    public function test_an_embedded_write_with_the_relation_mutate_ability_succeeds(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => true);

        $created = $this->writeJsonApi('POST', '/api/posts', $this->postWithModerator(null, '2'));
        $created->assertStatus(201);
        $id = $created->json('data.id');
        self::assertIsString($id);
        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/' . $id . '/relationships/moderator')->json('data'));

        $this->writeJsonApi('PATCH', '/api/posts/1', $this->postWithModerator('1', '1'))->assertOk();
        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/moderator')->json('data'));
    }

    public function test_an_embedded_write_without_the_gated_relation_skips_its_ability(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->writeJsonApi('PATCH', '/api/posts/1', [
            'data' => [
                'type' => 'posts',
                'id' => '1',
                'relationships' => ['author' => ['data' => ['type' => 'authors', 'id' => '2']]],
            ],
        ])->assertOk();
    }

    #[DefineEnvironment('enableAtomicOperations')]
    public function test_an_atomic_add_honours_the_relation_mutate_ability(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->atomic([['op' => 'add', 'data' => $this->postWithModerator(null, '2')['data']]])
            ->assertStatus(403);

        $this->readRel('/api/posts')->assertJsonCount(3, 'data');
    }

    #[DefineEnvironment('enableAtomicOperations')]
    public function test_an_atomic_update_honours_the_relation_mutate_ability(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => false);

        $this->atomic([['op' => 'update', 'ref' => ['type' => 'posts', 'id' => '1'], 'data' => $this->postWithModerator('1', '2')['data']]])
            ->assertStatus(403);

        self::assertNull($this->readRel('/api/posts/1/relationships/moderator')->json('data'));
    }

    #[DefineEnvironment('enableAtomicOperations')]
    public function test_an_atomic_write_with_the_relation_mutate_ability_succeeds(): void
    {
        Gate::define('curate', static fn(?Authenticatable $user): bool => true);

        $this->atomic([['op' => 'update', 'ref' => ['type' => 'posts', 'id' => '1'], 'data' => $this->postWithModerator('1', '2')['data']]])
            ->assertOk();

        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/1/relationships/moderator')->json('data'));
    }

    // --- the update gate on the stored and the changed resource ---------------

    public function test_an_owner_cannot_hand_a_resource_to_another_user_through_a_whole_update(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));

        $this->writeJsonApi('PATCH', '/api/posts/1', $this->postWithAuthor('1', '2', 'Handed over'))
            ->assertStatus(403);

        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/author')->json('data'));
        self::assertSame('Hello', $this->readRel('/api/posts/1')->json('data.attributes.title'));
    }

    public function test_an_owner_cannot_hand_a_resource_to_another_user_through_its_relationship_endpoint(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));

        $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '2'])
            ->assertStatus(403);
        $this->patchNull('/api/posts/1/relationships/author')->assertStatus(403);

        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/author')->json('data'));
    }

    public function test_an_owner_can_still_edit_their_own_resource(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));

        $this->writeJsonApi('PATCH', '/api/posts/1', $this->postWithAuthor('1', '1', 'Edited'))->assertOk();
        $this->writeRel('PATCH', '/api/posts/1/relationships/author', ['type' => 'authors', 'id' => '1'])->assertOk();
        $this->writeManyRel('POST', '/api/posts/1/relationships/tags', [['type' => 'tags', 'id' => '3']])->assertOk();

        self::assertSame('Edited', $this->readRel('/api/posts/1')->json('data.attributes.title'));
        self::assertSame(['1', '2', '3'], $this->linkageIds($this->readRel('/api/posts/1/relationships/tags')));
    }

    public function test_nobody_can_take_another_users_resource(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));

        $this->writeJsonApi('PATCH', '/api/posts/2', $this->postWithAuthor('2', '1', 'Taken'))->assertStatus(403);
        $this->writeRel('PATCH', '/api/posts/2/relationships/author', ['type' => 'authors', 'id' => '1'])->assertStatus(403);

        self::assertSame(['type' => 'authors', 'id' => '2'], $this->readRel('/api/posts/2/relationships/author')->json('data'));
        self::assertSame('World', $this->readRel('/api/posts/2')->json('data.attributes.title'));
    }

    public function test_a_caller_the_stored_resource_refuses_gets_403_before_validation(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));
        $invalid = ['data' => ['type' => 'posts', 'id' => '2', 'attributes' => ['title' => null]]];

        $this->writeJsonApi('PATCH', '/api/posts/2', $invalid)->assertStatus(403);

        $invalid['data']['id'] = '1';
        $this->writeJsonApi('PATCH', '/api/posts/1', $invalid)->assertStatus(422);
    }

    #[DefineEnvironment('enableAtomicOperations')]
    public function test_an_owner_cannot_hand_a_resource_to_another_user_in_an_atomic_batch(): void
    {
        $this->onlyTheAuthorMayUpdatePosts();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));

        $this->atomic([['op' => 'update', 'ref' => ['type' => 'posts', 'id' => '1'], 'data' => $this->postWithAuthor('1', '2', 'Handed over')['data']]])
            ->assertStatus(403);
        $this->atomic([['op' => 'update', 'ref' => ['type' => 'posts', 'id' => '1', 'relationship' => 'author'], 'data' => ['type' => 'authors', 'id' => '2']]])
            ->assertStatus(403);

        self::assertSame(['type' => 'authors', 'id' => '1'], $this->readRel('/api/posts/1/relationships/author')->json('data'));
        self::assertSame('Hello', $this->readRel('/api/posts/1')->json('data.attributes.title'));
    }

    /**
     * Lets `update` pass on a post only for the user its author is, on the post as stored and
     * as changed; every other subject passes.
     */
    protected function onlyTheAuthorMayUpdatePosts(): void
    {
        Gate::define('update', static function (?Authenticatable $user, mixed $subject = null): bool {
            if (!\is_object($subject) || !\str_starts_with((new \ReflectionClass($subject))->getShortName(), 'Post')) {
                return true;
            }
            /** @var mixed $author */
            $author = $subject->author ?? null;
            /** @var mixed $authorId */
            $authorId = \is_object($author) ? ($author->id ?? null) : null;
            /** @var mixed $userId */
            $userId = $user?->getAuthIdentifier();

            return \is_scalar($authorId) && \is_scalar($userId) && (string) $authorId === (string) $userId;
        });
    }

    /**
     * A posts update document setting the title and the `author` relationship.
     *
     * @return array{data: array<string, mixed>}
     */
    protected function postWithAuthor(string $id, string $authorId, string $title): array
    {
        return ['data' => [
            'type' => 'posts',
            'id' => $id,
            'attributes' => ['title' => $title],
            'relationships' => ['author' => ['data' => ['type' => 'authors', 'id' => $authorId]]],
        ]];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableAtomicOperations($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    /**
     * A posts document embedding the `moderator` relation, which declares `security(mutate: 'curate')`.
     *
     * @return array{data: array<string, mixed>}
     */
    protected function postWithModerator(?string $id, string $moderatorId): array
    {
        $data = ['type' => 'posts'];
        if ($id !== null) {
            $data['id'] = $id;
        }
        $data['attributes'] = ['title' => 'Moderated'];
        $data['relationships'] = ['moderator' => ['data' => ['type' => 'authors', 'id' => $moderatorId]]];

        return ['data' => $data];
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        $mediaType = self::MEDIA_TYPE . ';ext="https://jsonapi.org/ext/atomic"';

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * Lets the `update` ability pass only for the listed ids of `$type`. Calls stack, so
     * several types can each allow their own ids.
     */
    protected function allowUpdateOnlyOn(string $type, string ...$ids): void
    {
        $this->updatableIds[$type] = \array_values($ids);
        $classes = ['authors' => 'Author', 'posts' => 'Post'];
        $allowed = &$this->updatableIds;

        Gate::define('update', static function (?Authenticatable $user, mixed $subject = null) use (&$allowed, $classes): bool {
            if (!\is_object($subject)) {
                return false;
            }
            $short = (new \ReflectionClass($subject))->getShortName();
            foreach ($allowed as $allowedType => $allowedIds) {
                $prefix = $classes[$allowedType] ?? '';
                if ($prefix !== '' && \str_starts_with($short, $prefix)) {
                    /** @var mixed $id */
                    $id = $subject->id ?? null;

                    return \is_scalar($id) && \in_array((string) $id, $allowedIds, true);
                }
            }

            return false;
        });
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
