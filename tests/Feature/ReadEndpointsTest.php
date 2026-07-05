<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * End-to-end coverage of the Phase 0 read surface: the two fetch endpoints render
 * spec-valid JSON:API documents with the correct media type, a missing resource and an
 * unknown type both 404, and content negotiation rejects a non-JSON:API `Accept` (406)
 * or a parametrized `Content-Type` (415). The whole path — route → controller →
 * negotiate → dispatch → in-memory provider → render — is exercised through a real
 * Testbench kernel.
 *
 * Requests and assertions run through the shipped {@see InteractsWithJsonApi} kit (PLAN
 * decision 12), dogfooding the request builder + the JSON:API {@see JsonApiDocument} /
 * {@see JsonApiErrors} response macros alongside the native Laravel assertions.
 *
 * @internal
 */
#[CoversNothing]
final class ReadEndpointsTest extends TestCase
{
    use InteractsWithJsonApi;

    public function test_collection_returns_a_spec_valid_jsonapi_document(): void
    {
        $this->jsonApi()->get('/api/artists')
            ->assertOk()
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'artists')
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.0.attributes.name', 'Radiohead')
            ->assertJsonPath('jsonapi.version', '1.1')
            ->assertJsonApiSpecCompliant()
            ->assertFetchedMany(fn(JsonApiDocument $document) => $document->assertCollectionCount(2));
    }

    public function test_collection_computed_and_typed_attributes_render(): void
    {
        $this->jsonApi()->get('/api/albums')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attributes.explicit', false)
            ->assertJsonPath('data.0.attributes.averageRating', 9.8);

        // The trackCount attribute on artists is computed via extractUsing.
        $this->jsonApi()->get('/api/artists')
            ->assertJsonPath('data.0.attributes.trackCount', 3);
    }

    public function test_fetch_one_returns_the_resource(): void
    {
        $this->jsonApi()->get('/api/artists/1')
            ->assertOk()
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonPath('data.type', 'artists')
            ->assertJsonPath('data.id', '1')
            ->assertJsonPath('data.attributes.name', 'Radiohead')
            ->assertJsonPath('data.attributes.slug', 'radiohead')
            ->assertFetchedOne(fn(JsonApiDocument $document) => $document
                ->assertHasType('artists')
                ->assertHasId('1'));
    }

    public function test_fetch_one_with_a_natural_key_id(): void
    {
        $this->jsonApi()->get('/api/genres/trip-hop')
            ->assertOk()
            ->assertJsonPath('data.type', 'genres')
            ->assertJsonPath('data.id', 'trip-hop')
            ->assertJsonPath('data.attributes.name', 'Trip Hop');
    }

    public function test_unknown_id_returns_a_jsonapi_error_document(): void
    {
        $this->jsonApi()->get('/api/artists/999')
            ->assertStatus(404)
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonStructure(['errors' => [['status', 'title']]])
            ->assertJsonPath('errors.0.status', '404')
            ->assertJsonApiError(404, fn(JsonApiErrors $errors) => $errors->assertHasError(status: '404'));
    }

    public function test_unknown_type_returns_404(): void
    {
        $this->jsonApi()->get('/api/widgets')->assertStatus(404);
    }

    public function test_parametrized_accept_is_not_acceptable(): void
    {
        // Per the spec (and core's RequestValidator): a 406 is required only when every
        // JSON:API media-type instance in Accept is parametrized (only `ext`/`profile`
        // are allowed) — a bare `application/json` or `*/*` is legitimately accepted.
        $this->jsonApi()->withHeader('Accept', self::MEDIA_TYPE . '; charset=utf-8')->get('/api/artists')
            ->assertStatus(406)
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonPath('errors.0.status', '406');
    }

    public function test_parametrized_content_type_is_unsupported(): void
    {
        $this->jsonApi()->withHeader('Content-Type', self::MEDIA_TYPE . '; charset=utf-8')->get('/api/artists')
            ->assertStatus(415)
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonPath('errors.0.status', '415');
    }

    public function test_unrecognized_query_parameter_is_rejected(): void
    {
        $this->jsonApi()->withQueryParam('bogus', '1')->get('/api/artists')
            ->assertStatus(400)
            ->assertHeader('Content-Type', self::MEDIA_TYPE)
            ->assertJsonPath('errors.0.status', '400')
            ->assertJsonApiError(400);
    }
}
