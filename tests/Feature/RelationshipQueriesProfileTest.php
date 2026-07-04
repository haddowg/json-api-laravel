<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * The Relationship Queries profile end-to-end over the Eloquent reference (the SQL push-down):
 * the relationship (linkage) endpoint windowed by its own `?sort`/`?page`, and an included
 * to-many windowed from the PRIMARY request via `relatedQuery[<path>][sort]` under the
 * negotiated profile — both driven by the `groupLimit`/ROW_NUMBER batch (ADR 0006).
 *
 * @internal
 */
final class RelationshipQueriesProfileTest extends EloquentTestCase
{
    private const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theRelationshipEndpointWindowsToManyLinkageWhenQueried(): void
    {
        // Radiohead(1) owns albums 1/3/6/7; released_at DESC (the requested sort), page size 2 →
        // page 1 = in rainbows(6), amnesiac(7). The out-of-band windowed linkage replaces the
        // full-set render a plain (no-param) relationship GET produces.
        $response = $this->fetch('/api/artists/1/relationships/albums?sort=-releasedAt&page[size]=2');

        $response->assertOk();
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        self::assertCount(2, $data);
        self::assertSame([['type' => 'albums', 'id' => '6'], ['type' => 'albums', 'id' => '7']], $data);

        // A count-free windowed page emits a `next` link (a further page exists: 4 > 2), driven
        // by the hasMore probe, in the spec's plain form against the relationship endpoint.
        self::assertIsString($response->json('links.next'));
        self::assertIsString($response->json('links.self'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theRelationshipEndpointRendersTheFullLinkageWithoutQueryParameters(): void
    {
        // No query parameters → the whole association off the loaded parent (the Phase-3a
        // full-linkage contract is preserved; windowing is on-demand only).
        $response = $this->fetch('/api/artists/1/relationships/albums');

        $response->assertOk();
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        self::assertCount(4, $data);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedToManyIsWindowedFromThePrimaryRequestUnderTheProfile(): void
    {
        // With the profile negotiated, `relatedQuery[albums][sort]` windows the INCLUDED albums
        // linkage of the primary artist to page 1 of THAT sort — driven by the multi-parent SQL
        // push-down (groupLimit/ROW_NUMBER), supplied out-of-band. The linkage is re-ordered to
        // released_at DESC (in rainbows/amnesiac/kid a/ok computer = 6,7,3,1), not the natural
        // membership order a plain include renders (page 1 of the relation's default size holds
        // all four; the relationship-endpoint test proves the page SLICE with an explicit size).
        $response = $this->fetchWithProfile(
            '/api/artists/1?include=albums&relatedQuery[albums][sort]=-releasedAt',
        );

        $response->assertOk();

        /** @var list<array{type: string, id: string}> $linkage */
        $linkage = $response->json('data.relationships.albums.data');
        self::assertSame(
            [
                ['type' => 'albums', 'id' => '6'],
                ['type' => 'albums', 'id' => '7'],
                ['type' => 'albums', 'id' => '3'],
                ['type' => 'albums', 'id' => '1'],
            ],
            $linkage,
        );
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPlainIncludeRendersTheFullLinkageWithoutTheProfile(): void
    {
        // A plain `?include=albums` (no `relatedQuery`) renders the whole membership — the
        // windowing only fires under the negotiated profile + a relatedQuery, so a normal
        // include is untouched.
        $response = $this->fetch('/api/artists/1?include=albums');

        $response->assertOk();
        /** @var list<array{type: string, id: string}> $linkage */
        $linkage = $response->json('data.relationships.albums.data');
        self::assertCount(4, $linkage);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetchWithProfile(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"']);
    }
}
