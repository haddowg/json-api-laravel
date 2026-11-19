<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The dual-provider **pivot-WRITE** contract: a `belongsToMany` with declared
 * writable pivot fields (`orderedTracks` on `playlists`, carrying `position`/`weight` +
 * the server-owned `addedAt`) mutated through the relationship endpoints, asserted
 * identically against the Eloquent reference and the in-memory witness.
 *
 * These are the assertions that hold on BOTH providers: a full-meta replace/add/remove
 * changes the membership, a genuinely-new member missing its required `position` is a `422`
 * before persist (never a DB NOT-NULL `500`), and the cross-pivot-field `weight >= position`
 * rule rejects an inverted pair. The provider-asymmetric behaviour — Eloquent renders + stores
 * the pivot values, the in-memory witness stores none (the documented boundary, so `meta.pivot`
 * is Eloquent-only) — is asserted in the concretes ({@see EloquentPivotTest} /
 * {@see InMemoryPivotTest}).
 *
 * The seed graph (identical on both providers): playlist `1` owns four ordered tracks
 * (`1`/`2`/`3`/`4`), playlist `2` owns one (`1`), playlist `3` none; track `1` is shared
 * across playlists `1` and `2`.
 *
 * @internal
 */
#[CoversNothing]
abstract class PivotWriteTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public function test_patch_replaces_the_ordered_track_set_with_pivot_values(): void
    {
        // Playlist 2 owns [1]; replace it with tracks 2 and 3 carrying full pivot meta.
        $response = $this->writeMany('PATCH', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'weight' => 6]]],
            ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 6, 'weight' => 9]]],
        ]);

        $response->assertOk();
        self::assertSame(['2', '3'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
    }

    public function test_post_adds_ordered_tracks_with_pivot_values(): void
    {
        // Playlist 2 owns [1]; add track 4 with pivot meta — membership becomes [1, 4].
        $response = $this->writeMany('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'weight' => 8]]],
        ]);

        $response->assertOk();
        self::assertSame(['1', '4'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
    }

    public function test_delete_removes_ordered_tracks(): void
    {
        // Playlist 1 owns [1,2,3,4]; remove track 1 (a remove carries no pivot meta).
        $response = $this->writeMany('DELETE', '/api/playlists/1/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '1'],
        ]);

        $response->assertOk();
        self::assertSame(['2', '3', '4'], $this->linkageIds($this->readRel('/api/playlists/1/relationships/orderedTracks')));
    }

    public function test_a_new_member_missing_the_required_position_is_422(): void
    {
        // Track 4 is not in playlist 2, so it is a NEW row — its required `position` is absent,
        // a 422 before persist (never a DB NOT-NULL 500). Both providers treat it as new (the
        // witness stores no pivot, so every member is create-context).
        $response = $this->writeMany('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['weight' => 3]]],
        ]);

        $response->assertStatus(422);
        self::assertSame('VALIDATION_FAILED', $response->json('errors.0.code'));
        self::assertSame('/data/0/meta/pivot/position', $response->json('errors.0.source.pointer'));
    }

    public function test_an_undeclared_pivot_member_is_422(): void
    {
        // `meta.pivot` is closed: a member no pivot field names is refused at its pointer,
        // on the relationship endpoint and inside a whole-resource write, and nothing is written.
        $response = $this->writeMany('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 5, 'colour' => 'red']]],
        ]);

        $response->assertStatus(422);
        self::assertSame('/data/0/meta/pivot/colour', $response->json('errors.0.source.pointer'));
        self::assertSame('The member "colour" is not declared.', $response->json('errors.0.detail'));
        self::assertSame(['1'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));

        $whole = $this->json('PATCH', '/api/playlists/2', ['data' => [
            'type' => 'playlists',
            'id' => '2',
            'relationships' => ['orderedTracks' => ['data' => [['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['position' => 1, 'colour' => 'red']]]]]],
        ]], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
        $whole->assertStatus(422);
        self::assertSame('/data/relationships/orderedTracks/data/0/meta/pivot/colour', $whole->json('errors.0.source.pointer'));
    }

    public function test_a_weight_below_position_is_422(): void
    {
        // The cross-pivot-field rule `weight >= position` rejects an inverted pair over the
        // merged meta — both fields present in the incoming meta, so it fires on both providers.
        $response = $this->writeMany('POST', '/api/playlists/2/relationships/orderedTracks', [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 5, 'weight' => 2]]],
        ]);

        $response->assertStatus(422);
        self::assertSame('/data/0/meta/pivot/weight', $response->json('errors.0.source.pointer'));
    }

    public function test_whole_float_pivot_values_are_taken_on_an_add_and_a_replace(): void
    {
        $this->sendKeepingFloats('POST', '/api/playlists/2/relationships/orderedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7.0, 'weight' => 8.0]]],
        ]])->assertOk();
        $this->sendKeepingFloats('PATCH', '/api/playlists/3/relationships/orderedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5.0, 'weight' => 6.0]]],
        ]])->assertOk();

        self::assertSame(['1', '4'], $this->linkageIds($this->readRel('/api/playlists/2/relationships/orderedTracks')));
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/playlists/3/relationships/orderedTracks')));
    }

    public function test_a_create_takes_whole_float_pivot_values(): void
    {
        $response = $this->sendKeepingFloats('POST', '/api/playlists', $this->playlistWith(['position' => 5.0, 'weight' => 6.0]));

        $response->assertCreated();
        $id = $response->json('data.id');
        self::assertIsString($id);
        self::assertSame(['2'], $this->linkageIds($this->readRel('/api/playlists/' . $id . '/relationships/orderedTracks')));
    }

    public function test_a_fractional_or_non_number_pivot_value_is_422_at_its_pointer(): void
    {
        $this->sendKeepingFloats('POST', '/api/playlists/2/relationships/orderedTracks', ['data' => [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'weight' => 'heavy']]],
        ]])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/data/0/meta/pivot/weight');

        $this->sendKeepingFloats('POST', '/api/playlists', $this->playlistWith(['position' => 5.5]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/relationships/orderedTracks/data/0/meta/pivot/position');
    }

    /**
     * A new playlist holding track 2 with the given pivot values.
     *
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    protected function playlistWith(array $pivot): array
    {
        return ['data' => [
            'type' => 'playlists',
            'attributes' => ['title' => 'Numbers'],
            'relationships' => ['orderedTracks' => ['data' => [['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => $pivot]]]]],
        ]];
    }

    /**
     * Sends the document keeping a whole float's `.0`, as a client writing `5.0` sends it.
     *
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function sendKeepingFloats(string $method, string $uri, array $document): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], \json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * PATCH/POST/DELETE a to-many relationship endpoint with a list of linkage identifiers
     * (each optionally carrying pivot `meta`).
     *
     * @param list<array<string, mixed>> $identifiers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeMany(string $method, string $uri, array $identifiers): TestResponse
    {
        return $this->json($method, $uri, ['data' => $identifiers], [
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
