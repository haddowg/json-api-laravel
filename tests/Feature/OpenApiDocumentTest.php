<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Document-shape tests for the projected OpenAPI 3.1 document (PLAN decision 11): the
 * {@see DocumentFactory} composes the package {@see \haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource}
 * with core's pure projector, so these assertions pin that the metadata source feeds the
 * projector the right facts — the same facts the Symfony bundle feeds, which Phase 5
 * diffs byte-for-byte. They exercise the two new core seams transparently: a constraint
 * self-describing its JSON Schema (`ProvidesJsonSchema`) and a filter describing its own
 * query parameter (`DescribesQueryParameter`).
 *
 * @internal
 */
final class OpenApiDocumentTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_a_valid_openapi_31_envelope(): void
    {
        $doc = $this->document();

        $this->assertStringStartsWith('3.1', $this->stringAt($doc, 'openapi'));
        $this->assertSame('JSON:API', $this->at($doc, 'info', 'title'));
        $this->assertSame('1.0.0', $this->at($doc, 'info', 'version'));
        $this->assertArrayHasKey('paths', $doc);
        $this->assertArrayHasKey('components', $doc);
    }

    #[Test]
    #[Group('openapi')]
    public function it_emits_one_path_per_exposed_operation(): void
    {
        $doc = $this->document();

        // albums is fully writable: collection GET+POST, resource GET+PATCH+DELETE.
        $collection = $this->arrayAt($doc, 'paths', '/albums');
        $this->assertArrayHasKey('get', $collection);
        $this->assertArrayHasKey('post', $collection);
        $resource = $this->arrayAt($doc, 'paths', '/albums/{id}');
        $this->assertArrayHasKey('get', $resource);
        $this->assertArrayHasKey('patch', $resource);
        $this->assertArrayHasKey('delete', $resource);

        // artists is readOnly: no write verbs are routed, so none are documented.
        $artists = $this->arrayAt($doc, 'paths', '/artists');
        $this->assertArrayHasKey('get', $artists);
        $this->assertArrayNotHasKey('post', $artists);
        $artist = $this->arrayAt($doc, 'paths', '/artists/{id}');
        $this->assertArrayHasKey('get', $artist);
        $this->assertArrayNotHasKey('patch', $artist);
        $this->assertArrayNotHasKey('delete', $artist);
    }

    #[Test]
    #[Group('openapi')]
    public function it_documents_relationship_and_related_endpoints(): void
    {
        $paths = $this->arrayAt($this->document(), 'paths');

        // albums → artist (to-one): related + relationship endpoints.
        $this->assertArrayHasKey('/albums/{id}/artist', $paths);
        $this->assertArrayHasKey('/albums/{id}/relationships/artist', $paths);

        // artists → albums (to-many): related + relationship endpoints.
        $this->assertArrayHasKey('/artists/{id}/albums', $paths);
        $this->assertArrayHasKey('/artists/{id}/relationships/albums', $paths);
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_a_self_describing_constraint_into_the_attribute_schema(): void
    {
        $doc = $this->document();

        // `Str::make('title')->maxLength(200)` — the MaxLength constraint self-describes its
        // JSON Schema via core's ProvidesJsonSchema seam, consumed transparently by the
        // projector (no Laravel-side consumption code).
        $this->assertSame('string', $this->at($doc, 'components', 'schemas', 'AlbumsAttributes', 'properties', 'title', 'type'));
        $this->assertSame(200, $this->at($doc, 'components', 'schemas', 'AlbumsAttributes', 'properties', 'title', 'maxLength'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_filter_query_parameters_via_the_describe_seam(): void
    {
        $names = $this->parameterNames($this->document(), '/albums', 'get');

        // Each filter describes its own query parameter (DescribesQueryParameter),
        // projected as a `filter[<key>]` parameter.
        $this->assertContains('filter[title]', $names);
        $this->assertContains('filter[releasedRange]', $names);
        $this->assertContains('filter[status]', $names);
        $this->assertContains('filter[unrated]', $names);
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_the_standard_query_parameter_families(): void
    {
        $names = $this->parameterNames($this->document(), '/albums', 'get');

        $this->assertContains('sort', $names);
        $this->assertContains('include', $names);
        $this->assertContains('fields[albums]', $names);
        // The page family projects as one `page` deepObject parameter (ADR 0130) whose
        // object schema carries the page-based number/size keys.
        $this->assertContains('page', $names);
        $this->assertSame(['number', 'size'], $this->pageParameterPropertyKeys($this->document(), '/albums'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_pins_the_sort_parameter_tokens_from_all_sorts(): void
    {
        // Blueprint risk #1: sorts() vs allSorts() is the top silent byte-compat drift. The
        // sort parameter's items.enum must carry every field-derived sortable (title, status,
        // availableFrom, releasedAt) in both ascending and `-` descending forms — a regression
        // to sorts() would drop the field-derived tokens (albums declares no explicit sorts()).
        $sort = $this->sortParameter($this->document(), '/albums');
        $enum = $this->arrayAt($sort, 'schema', 'items', 'enum');

        foreach (['title', 'status', 'availableFrom', 'releasedAt'] as $token) {
            self::assertContains($token, $enum, "ascending sort token {$token}");
            self::assertContains('-' . $token, $enum, "descending sort token -{$token}");
        }
    }

    #[Test]
    #[Group('openapi')]
    public function it_enumerates_includable_paths_walking_the_relation_graph(): void
    {
        $include = $this->includeParameter($this->document(), '/albums');

        // albums → artist, then artist → albums (cycle-guarded, depth-capped): the walk
        // yields the finite dotted prefix set.
        $this->assertSame(['artist', 'artist.albums'], $this->arrayAt($include, 'schema', 'items', 'enum'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_advertises_withcount_on_a_countable_related_collection(): void
    {
        $names = $this->parameterNames($this->document(), '/artists/{id}/albums', 'get');

        // `HasMany::make('albums')->countable()` — the related-collection endpoint advertises
        // the `?withCount` opt-in.
        $this->assertContains('withCount', $names);
    }

    #[Test]
    #[Group('openapi')]
    public function it_requires_a_client_id_on_a_require_client_id_type(): void
    {
        $required = $this->arrayAt($this->document(), 'components', 'schemas', 'GenresCreateRequest', 'properties', 'data', 'required');

        // genres declares `Id::make()->requireClientId()`, so a create MUST carry `id`.
        $this->assertContains('id', $required);
        $this->assertContains('type', $required);
    }

    #[Test]
    #[Group('openapi')]
    public function it_does_not_require_a_client_id_on_a_server_generated_type(): void
    {
        $required = $this->arrayAt($this->document(), 'components', 'schemas', 'AlbumsCreateRequest', 'properties', 'data', 'required');

        // albums assigns its own id (client ids forbidden), so `id` is not required on create.
        $this->assertNotContains('id', $required);
        $this->assertContains('type', $required);
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_the_shared_error_document_schema(): void
    {
        $schemas = $this->arrayAt($this->document(), 'components', 'schemas');

        $this->assertArrayHasKey('Error', $schemas);
        $this->assertArrayHasKey('ErrorDocument', $schemas);
        $this->assertArrayHasKey('errors', $this->arrayAt($schemas, 'ErrorDocument', 'properties'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_derives_humanized_default_tags_and_defines_them(): void
    {
        $tagNames = [];
        foreach ($this->arrayAt($this->document(), 'tags') as $tag) {
            if (\is_array($tag) && isset($tag['name']) && \is_string($tag['name'])) {
                $tagNames[] = $tag['name'];
            }
        }

        $this->assertContains('Albums', $tagNames);
        $this->assertContains('Artists', $tagNames);
        $this->assertContains('Genres', $tagNames);
    }

    /**
     * The `include` query parameter object on `GET {path}`.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<array-key, mixed>
     */
    private function includeParameter(array $doc, string $path): array
    {
        foreach ($this->arrayAt($doc, 'paths', $path, 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'include') {
                return $parameter;
            }
        }

        self::fail(\sprintf('The `include` parameter was not found on GET %s.', $path));
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<array-key, mixed>
     */
    private function sortParameter(array $doc, string $path): array
    {
        foreach ($this->arrayAt($doc, 'paths', $path, 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'sort') {
                return $parameter;
            }
        }

        self::fail(\sprintf('The `sort` parameter was not found on GET %s.', $path));
    }
}
