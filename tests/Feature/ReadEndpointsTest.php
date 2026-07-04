<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * End-to-end coverage of the Phase 0 read surface: the two fetch endpoints render
 * spec-valid JSON:API documents with the correct media type, a missing resource and an
 * unknown type both 404, and content negotiation rejects a non-JSON:API `Accept` (406)
 * or a parametrized `Content-Type` (415). The whole path — route → controller →
 * negotiate → dispatch → in-memory provider → render — is exercised through a real
 * Testbench kernel.
 *
 * @internal
 */
#[CoversNothing]
final class ReadEndpointsTest extends TestCase
{
    public function test_collection_returns_a_spec_valid_jsonapi_document(): void
    {
        $response = $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.type', 'artists');
        $response->assertJsonPath('data.0.id', '1');
        $response->assertJsonPath('data.0.attributes.name', 'Radiohead');
        $response->assertJsonPath('jsonapi.version', '1.1');
    }

    public function test_collection_computed_and_typed_attributes_render(): void
    {
        $response = $this->get('/api/albums', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.attributes.explicit', false);
        $response->assertJsonPath('data.0.attributes.averageRating', 9.8);
        // The trackCount attribute on artists is computed via extractUsing.
        $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE])
            ->assertJsonPath('data.0.attributes.trackCount', 3);
    }

    public function test_fetch_one_returns_the_resource(): void
    {
        $response = $this->get('/api/artists/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('data.type', 'artists');
        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.attributes.name', 'Radiohead');
        $response->assertJsonPath('data.attributes.slug', 'radiohead');
    }

    public function test_fetch_one_with_a_natural_key_id(): void
    {
        $response = $this->get('/api/genres/trip-hop', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'genres');
        $response->assertJsonPath('data.id', 'trip-hop');
        $response->assertJsonPath('data.attributes.name', 'Trip Hop');
    }

    public function test_unknown_id_returns_a_jsonapi_error_document(): void
    {
        $response = $this->get('/api/artists/999', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonStructure(['errors' => [['status', 'title']]]);
        $response->assertJsonPath('errors.0.status', '404');
    }

    public function test_unknown_type_returns_404(): void
    {
        $this->get('/api/widgets', ['Accept' => self::MEDIA_TYPE])->assertStatus(404);
    }

    public function test_parametrized_accept_is_not_acceptable(): void
    {
        // Per the spec (and core's RequestValidator): a 406 is required only when every
        // JSON:API media-type instance in Accept is parametrized (only `ext`/`profile`
        // are allowed) — a bare `application/json` or `*/*` is legitimately accepted.
        $response = $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE . '; charset=utf-8']);

        $response->assertStatus(406);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('errors.0.status', '406');
    }

    public function test_parametrized_content_type_is_unsupported(): void
    {
        $response = $this->get('/api/artists', [
            'Accept' => self::MEDIA_TYPE,
            'Content-Type' => self::MEDIA_TYPE . '; charset=utf-8',
        ]);

        $response->assertStatus(415);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('errors.0.status', '415');
    }

    public function test_unrecognized_query_parameter_is_rejected(): void
    {
        $response = $this->get('/api/artists?bogus=1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(400);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('errors.0.status', '400');
    }
}
