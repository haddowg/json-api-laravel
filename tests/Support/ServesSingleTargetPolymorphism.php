<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Support;

use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A `MorphTo` and a `MorphToMany` over the single type `tags` (the blog fixtures' `pin` and
 * `pinned`) are served like the two-type `feature` and `related`, not like the monomorphic
 * `tags` relation, and the document describes them as unions (core ADR 0184). Shared by the
 * Eloquent and in-memory polymorphic tests so both providers answer the same requests.
 */
trait ServesSingleTargetPolymorphism
{
    use InteractsWithOpenApiDocument;

    public function test_a_single_type_polymorphic_relation_is_projected_as_a_union(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        $identifier = ['$ref' => '#/components/schemas/TagsResourceIdentifier'];

        self::assertSame(
            ['anyOf' => [['anyOf' => [$identifier]], ['type' => 'null']]],
            $this->at($doc, 'components', 'schemas', 'PostsPinRelationship', 'properties', 'data'),
        );
        self::assertSame(
            ['type' => 'array', 'items' => ['anyOf' => [$identifier]]],
            $this->at($doc, 'components', 'schemas', 'PostsPinnedRelationship', 'properties', 'data'),
        );

        self::assertArrayNotHasKey('parameters', $this->arrayAt($doc, 'paths', '/posts/{id}/relationships/pin', 'get'));
        self::assertArrayNotHasKey('parameters', $this->arrayAt($doc, 'paths', '/posts/{id}/relationships/pinned', 'get'));
        self::assertNotContains('filter[label]', $this->parameterNames($doc, '/posts/{id}/pinned', 'get'));
        self::assertNotContains('sort', $this->parameterNames($doc, '/posts/{id}/pinned', 'get'));

        // The monomorphic relation over the same type still advertises its vocabulary.
        self::assertContains('filter[label]', $this->parameterNames($doc, '/posts/{id}/relationships/tags', 'get'));
    }

    public function test_a_single_type_polymorphic_relation_reads_its_linkage_and_related_resources(): void
    {
        $pin = $this->getJsonApi('/api/posts/1/pin');
        $pin->assertOk();
        self::assertSame(['tags', '1', 'php'], [$pin->json('data.type'), $pin->json('data.id'), $pin->json('data.attributes.label')]);

        $pinLinkage = $this->getJsonApi('/api/posts/1/relationships/pin');
        $pinLinkage->assertOk();
        self::assertSame(['type' => 'tags', 'id' => '1'], $pinLinkage->json('data'));

        self::assertNull($this->getJsonApi('/api/posts/2/pin')->json('data'));

        $pinned = $this->getJsonApi('/api/posts/1/pinned');
        $pinned->assertOk();
        self::assertSame([['type' => 'tags', 'id' => '1']], \array_map(
            static fn(mixed $row): array => \array_intersect_key((array) $row, ['type' => true, 'id' => true]),
            (array) $pinned->json('data'),
        ));

        $pinnedLinkage = $this->getJsonApi('/api/posts/2/relationships/pinned');
        $pinnedLinkage->assertOk();
        self::assertSame([['type' => 'tags', 'id' => '2']], $pinnedLinkage->json('data'));
    }

    public function test_a_single_type_polymorphic_relation_rejects_the_related_types_filter_and_sort(): void
    {
        // The monomorphic `tags` relation honours them, so the 400s below come from the relation.
        $this->getJsonApi('/api/posts/1/tags?filter[label]=php')->assertOk();
        $this->getJsonApi('/api/posts/1/relationships/tags?sort=label')->assertOk();

        $this->getJsonApi('/api/posts/1/pin?filter[label]=php')->assertStatus(400);
        $this->getJsonApi('/api/posts/1/pinned?filter[label]=php')->assertStatus(400);
        $this->getJsonApi('/api/posts/1/pinned?sort=label')->assertStatus(400);
        $this->getJsonApi('/api/posts/1/relationships/pinned?filter[label]=php')->assertStatus(400);
        $this->getJsonApi('/api/posts/1/relationships/pinned?sort=label')->assertStatus(400);
        $this->getJsonApi('/api/posts/1/relationships/pinned?page[size]=1')->assertStatus(400);
    }

    /**
     * Requests a two-type relation and its one-type twin answer the same way.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function twinRequests(): iterable
    {
        foreach (['', '?filter[label]=php', '?sort=label', '?page[size]=1', '?page[number]=1', '?withCount=_self_'] as $query) {
            // Post 2 holds the json tag in both, so the two pages match.
            yield 'related to-many ' . $query => ['/api/posts/2/related', '/api/posts/2/pinned', $query];
            yield 'relationship to-many ' . $query => ['/api/posts/2/relationships/related', '/api/posts/2/relationships/pinned', $query];
            yield 'related to-one ' . $query => ['/api/posts/1/feature', '/api/posts/1/pin', $query];
            yield 'relationship to-one ' . $query => ['/api/posts/1/relationships/feature', '/api/posts/1/relationships/pin', $query];
        }
    }

    #[DataProvider('twinRequests')]
    public function test_a_single_type_polymorphic_relation_answers_like_a_two_type_one(string $twoTypes, string $oneType, string $query): void
    {
        $expected = $this->getJsonApi($twoTypes . $query);
        $actual = $this->getJsonApi($oneType . $query);

        self::assertSame($expected->getStatusCode(), $actual->getStatusCode(), (string) $actual->getContent());
        self::assertSame($expected->json('errors.0.source'), $actual->json('errors.0.source'));
        self::assertSame(
            \array_keys((array) ($expected->json('links') ?? [])),
            \array_keys((array) ($actual->json('links') ?? [])),
        );
    }

    public function test_a_related_query_on_a_single_type_polymorphic_relation_is_rejected(): void
    {
        $profile = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';
        $accept = ['Accept' => 'application/vnd.api+json;profile="' . $profile . '"'];

        $this->get('/api/posts/1?include=tags&relatedQuery[tags][filter][label]=php', $accept)->assertOk();
        $this->get('/api/posts/1?include=pinned&relatedQuery[pinned][filter][label]=php', $accept)->assertStatus(400);
        $this->get('/api/posts/1?include=pin&relatedQuery[pin][filter][label]=php', $accept)->assertStatus(400);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function getJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => 'application/vnd.api+json']);
    }
}
