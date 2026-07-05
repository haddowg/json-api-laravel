<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * End-to-end coverage of the Phase 1 read surface backed by the reference
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}: the whole
 * path — route → controller → negotiate → dispatch → Eloquent provider (real SQLite) →
 * render — proves filters, sorts, the counted and count-free pagination arms, the
 * singular-filter collapse, the snake-column seam (a `storedAs()` attribute both renders
 * and sorts), and the query-parameter 400s all work Eloquent-backed.
 *
 * The resources and seed data are the SAME ones the in-memory suite uses (shared
 * {@see \Workbench\App\Support\Fixtures}), so these assertions are the Eloquent half of
 * the dual-provider conformance premise.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentReadEndpointsTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixtures();
    }

    public function test_collection_is_served_from_eloquent_with_the_default_sort(): void
    {
        $response = $this->get('/api/artists', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonCount(2, 'data');
        // Default sort created_at ASC → Radiohead (1985) before Portishead (1991).
        $response->assertJsonPath('data.0.id', '1');
        $response->assertJsonPath('data.0.type', 'artists');
        $response->assertJsonPath('data.0.attributes.name', 'Radiohead');
        // trackCount is computed off the `track_count` column via extractUsing.
        $response->assertJsonPath('data.0.attributes.trackCount', 3);
        $response->assertJsonPath('data.1.id', '2');
        // The snake-column seam: `createdAt` (storedAs created_at) renders from the cast.
        $createdAt = $response->json('data.0.attributes.createdAt');
        self::assertIsString($createdAt);
        self::assertStringStartsWith('1985-01-01', $createdAt);
    }

    public function test_fetch_one_is_served_from_eloquent(): void
    {
        $response = $this->get('/api/artists/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'artists');
        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.attributes.slug', 'radiohead');
    }

    public function test_fetch_one_with_a_natural_string_key(): void
    {
        $response = $this->get('/api/genres/trip-hop', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.id', 'trip-hop');
        $response->assertJsonPath('data.attributes.name', 'Trip Hop');
    }

    public function test_unknown_id_is_a_404(): void
    {
        $this->get('/api/artists/999', ['Accept' => self::MEDIA_TYPE])->assertStatus(404);
    }

    public function test_contains_filter_is_case_insensitive(): void
    {
        // The ASCII case-insensitivity parity probe (bundle R1): `RADIO` matches
        // `Radiohead` on the Eloquent provider exactly as `stripos` does in memory.
        $response = $this->get('/api/artists?filter[nameContains]=RADIO', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', '1');
    }

    public function test_explicit_sort_overrides_the_default(): void
    {
        // ?sort=name (ascending) → Portishead (P) before Radiohead (R).
        $response = $this->get('/api/artists?sort=name', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.0.id', '2');
        $response->assertJsonPath('data.1.id', '1');
    }

    public function test_count_free_pagination_partial_page_reports_more(): void
    {
        $response = $this->get('/api/artists?page[size]=1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        // Default sort created_at ASC → first page is Radiohead.
        $response->assertJsonPath('data.0.id', '1');
        // Count-free: no total, but a next link (hasMore from the N+1 probe).
        $response->assertJsonMissingPath('meta.total');
        $response->assertJsonMissingPath('meta.page.total');
        self::assertNotNull($response->json('links.next'));

        $page2 = $this->get('/api/artists?page[number]=2&page[size]=1', ['Accept' => self::MEDIA_TYPE]);
        $page2->assertOk();
        $page2->assertJsonCount(1, 'data');
        $page2->assertJsonPath('data.0.id', '2');
        // Last page: no further next link.
        self::assertNull($page2->json('links.next'));
    }

    public function test_singular_filter_collapses_to_a_single_resource(): void
    {
        $response = $this->get('/api/artists?filter[slug]=radiohead', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        // A singular filter renders a single resource object, not a collection.
        $response->assertJsonPath('data.type', 'artists');
        $response->assertJsonPath('data.id', '1');
    }

    public function test_albums_render_the_counted_pagination_arm(): void
    {
        $response = $this->get('/api/albums', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        // Default sort released_at DESC → OK Computer (1997) before Dummy (1994).
        $response->assertJsonPath('data.0.id', '1');
        $response->assertJsonPath('data.0.attributes.averageRating', 9.8);
        $response->assertJsonPath('data.0.attributes.explicit', false);
        // The counting paginator (withCount): meta.page.total + top-level meta.total.
        $response->assertJsonPath('meta.page.total', 2);
        $response->assertJsonPath('meta.total', 2);
        self::assertNotNull($response->json('links.last'));
    }

    public function test_a_range_filter_narrows_and_the_counted_total_reflects_the_filtered_collection(): void
    {
        // The structured range over the `released_at` (releasedRange) on the counted
        // albums arm: 1997+ keeps only OK Computer (1997), and meta.page.total reflects the
        // FILTERED collection, not the full count. (A null-bearing range now lives
        // alongside it, filter[rating], since core ADR 0116 aligned the witness with SQL
        // null-exclusion — see docs/adr/0003.)
        $response = $this->get('/api/albums?filter[releasedRange][min]=1997-01-01', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', '1');
        // The counted total reflects the filtered collection.
        $response->assertJsonPath('meta.page.total', 1);
    }

    public function test_date_range_filter_narrows_by_released_at(): void
    {
        $response = $this->get('/api/albums?filter[releasedRange][min]=1995-01-01', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', '1');
    }

    public function test_boolean_filter_matches_none_when_all_are_false(): void
    {
        $response = $this->get('/api/albums?filter[explicit]=1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.page.total', 0);
    }

    public function test_status_in_filter_matches_the_set(): void
    {
        $response = $this->get('/api/albums?filter[status]=released,archived', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_unknown_filter_key_is_a_400(): void
    {
        $response = $this->get('/api/artists?filter[nope]=x', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.0.status', '400');
    }

    public function test_unknown_sort_field_is_a_400(): void
    {
        $this->get('/api/artists?sort=bogus', ['Accept' => self::MEDIA_TYPE])->assertStatus(400);
    }
}
