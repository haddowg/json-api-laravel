<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\BlogRelationsServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Exercises the in-memory witness's **polymorphic** relation reads (to-one AND to-many)
 * plus the `belongsToMany` linkage and the null-to-one arm, over an isolated blog fixture
 * set (authors + tags + posts) — no Eloquent morph map or pivot table needed, since the
 * witness resolves a morph member by object class and the in-memory pivot meta is empty by
 * design.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPolymorphicRelationshipTest extends Orchestra
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
            BlogRelationsServiceProvider::class,
        ];
    }

    // --- polymorphic to-one (MorphTo) ----------------------------------------

    public function test_polymorphic_to_one_resolves_the_members_own_type(): void
    {
        // post 1's feature is a Tag; post 2's is an Author — each renders the member's
        // own JSON:API type, resolved by object class (the morph-alias-decoupled design).
        $tagFeature = $this->fetch('/api/posts/1/feature');
        $tagFeature->assertOk();
        self::assertSame('tags', $tagFeature->json('data.type'));
        self::assertSame('2', $tagFeature->json('data.id'));
        self::assertSame('json', $tagFeature->json('data.attributes.label'));

        $authorFeature = $this->fetch('/api/posts/2/feature');
        $authorFeature->assertOk();
        self::assertSame('authors', $authorFeature->json('data.type'));
        self::assertSame('1', $authorFeature->json('data.id'));
    }

    public function test_polymorphic_to_one_that_is_null_renders_data_null(): void
    {
        $response = $this->fetch('/api/posts/3/feature');

        $response->assertOk();
        self::assertNull($response->json('data'));
    }

    public function test_polymorphic_to_one_rejects_a_filter(): void
    {
        // A polymorphic to-one has no shared filter vocabulary — any filter key is a 400.
        $this->fetch('/api/posts/1/feature?filter[label]=json')->assertStatus(400);
    }

    // --- polymorphic to-many (MorphToMany) -----------------------------------

    public function test_polymorphic_to_many_renders_mixed_members(): void
    {
        $response = $this->fetch('/api/posts/1/related');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $types = array_column((array) $response->json('data'), 'type');
        sort($types);
        self::assertSame(['authors', 'tags'], $types);
    }

    public function test_polymorphic_to_many_relationship_endpoint_renders_mixed_linkage(): void
    {
        $response = $this->fetch('/api/posts/1/relationships/related');

        $response->assertOk();
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        self::assertCount(2, $data);
        $identifiers = array_map(static fn(array $row): string => $row['type'] . ':' . $row['id'], $data);
        sort($identifiers);
        self::assertSame(['authors:1', 'tags:1'], $identifiers);
    }

    public function test_polymorphic_to_many_rejects_filter_and_sort(): void
    {
        // No single related type, so no shared filter/sort vocabulary — both are 400.
        $this->fetch('/api/posts/1/related?filter[label]=php')->assertStatus(400);
        $this->fetch('/api/posts/1/related?sort=label')->assertStatus(400);
    }

    public function test_include_of_a_polymorphic_relation_expands_lazily(): void
    {
        // The include batcher skips polymorphic relations, but the transformer still
        // expands `?include` (rendered lazily, per-object) into `included`.
        $feature = $this->fetch('/api/posts/1?include=feature');
        $feature->assertOk();
        self::assertSame('tags', $feature->json('included.0.type'));
        self::assertSame('2', $feature->json('included.0.id'));

        $related = $this->fetch('/api/posts/1?include=related');
        $related->assertOk();
        $types = array_column((array) $related->json('included'), 'type');
        sort($types);
        self::assertSame(['authors', 'tags'], $types);
    }

    // --- belongsToMany + monomorphic to-one ----------------------------------

    public function test_belongs_to_many_renders_the_related_collection(): void
    {
        $response = $this->fetch('/api/posts/1/tags');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        self::assertSame('tags', $response->json('data.0.type'));
        // Assert MEMBERSHIP, not just count — the same sorted-ids the Eloquent twin
        // (EloquentPolymorphicRelationshipTest) asserts, so the two providers referee
        // identical belongsToMany membership, not merely cardinality.
        $ids = array_column((array) $response->json('data'), 'id');
        sort($ids);
        self::assertSame(['1', '2'], $ids);
    }

    public function test_belongs_to_many_linkage_carries_no_pivot_meta_in_memory(): void
    {
        // The in-memory store models no pivot, so the linkage identifiers carry no
        // `meta.pivot` (pivot READ is an Eloquent-only capability).
        $response = $this->fetch('/api/posts/1/relationships/tags');

        $response->assertOk();
        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        self::assertCount(2, $data);
        foreach ($data as $identifier) {
            self::assertArrayNotHasKey('meta', $identifier);
        }
    }

    public function test_monomorphic_to_one_that_is_null_renders_data_null(): void
    {
        $response = $this->fetch('/api/posts/3/author');

        $response->assertOk();
        self::assertNull($response->json('data'));
    }

    public function test_monomorphic_to_one_renders_the_owner(): void
    {
        $response = $this->fetch('/api/posts/1/author');

        $response->assertOk();
        self::assertSame('authors', $response->json('data.type'));
        self::assertSame('1', $response->json('data.id'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
