<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fixed-membership acceptance suite: `cannotChangeMembership()` on the workbench
 * `playlists` type's `fixedTracks` and `gatedTracks` views over the `tracks` join, run
 * identically against the in-memory witness ({@see InMemoryFixedMembershipConformanceTest})
 * and the reference Eloquent provider ({@see EloquentFixedMembershipConformanceTest}).
 *
 * A replace that reorders or repeats the current members is accepted, one that adds or drops a
 * member is a `403 MEMBERSHIP_CHANGE_PROHIBITED` at the relationship pointer, on the
 * relationship endpoint and as embedded linkage in a whole-resource `PATCH`. A `POST` or
 * `DELETE` gets the addition or removal `403`, and a create sets the members freely.
 * `gatedTracks` fixes them only on a public playlist (1 and 2, not 3). Playlist 1 holds tracks
 * 1 to 4 and playlist 2 holds track 1, per the shared {@see \Workbench\App\Support\ConformanceFixtures}.
 */
abstract class FixedMembershipConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    // --- the relationship endpoint ----------------------------------------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplaceThatReordersTheMembersIsAccepted(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/playlists/1/relationships/fixedTracks', ['data' => [
            $this->track('4'), $this->track('3'), $this->track('2'), $this->track('1'), $this->track('4'),
        ]]);

        $response->assertOk();
        self::assertSame(['1', '2', '3', '4'], $this->memberIds(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplaceThatAddsAMemberIsForbidden(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/playlists/2/relationships/fixedTracks', ['data' => [
            $this->track('1'), $this->track('5'),
        ]]);

        $this->assertMembershipChangeProhibited($response, 'fixedTracks');
        self::assertSame(['1'], $this->memberIds(2));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplaceThatDropsAMemberIsForbidden(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/playlists/1/relationships/fixedTracks', ['data' => [
            $this->track('1'), $this->track('2'), $this->track('3'),
        ]]);

        $this->assertMembershipChangeProhibited($response, 'fixedTracks');
        self::assertSame(['1', '2', '3', '4'], $this->memberIds(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function postingToAFixedRelationIsAnAdditionProhibited(): void
    {
        $response = $this->writeJsonApi('POST', '/api/playlists/2/relationships/fixedTracks', ['data' => [$this->track('5')]]);

        $response->assertStatus(403);
        self::assertSame('ADDITION_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame(['1'], $this->memberIds(2));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromAFixedRelationIsARemovalProhibited(): void
    {
        $response = $this->writeJsonApi('DELETE', '/api/playlists/1/relationships/fixedTracks', ['data' => [$this->track('1')]]);

        $response->assertStatus(403);
        self::assertSame('REMOVAL_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame(['1', '2', '3', '4'], $this->memberIds(1));
    }

    // --- whole-resource writes ----------------------------------------------------

    #[Test]
    #[Group('spec:updating-resources')]
    public function anEmbeddedReplaceThatReordersTheMembersIsAccepted(): void
    {
        $response = $this->patchPlaylist('1', 'fixedTracks', [$this->track('3'), $this->track('1'), $this->track('4'), $this->track('2')]);

        $response->assertOk();
        self::assertSame(['1', '2', '3', '4'], $this->memberIds(1));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anEmbeddedReplaceThatChangesTheMembersIsForbidden(): void
    {
        $response = $this->patchPlaylist('2', 'fixedTracks', [$this->track('2')]);

        $this->assertMembershipChangeProhibited($response, 'fixedTracks');
        self::assertSame(['1'], $this->memberIds(2));
        self::assertSame('Solo', $this->getJsonApi('/api/playlists/2')->json('data.attributes.title'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateSetsTheMembersFreely(): void
    {
        $response = $this->writeJsonApi('POST', '/api/playlists', ['data' => [
            'type' => 'playlists',
            'attributes' => ['title' => 'Fresh', 'public' => true],
            'relationships' => ['fixedTracks' => ['data' => [$this->track('2'), $this->track('5')]]],
        ]]);

        $response->assertCreated();
        $id = $response->json('data.id');
        self::assertIsString($id);
        self::assertSame(['2', '5'], $this->linkageIds($this->getJsonApi('/api/playlists/' . $id . '/relationships/fixedTracks')));
    }

    // --- a closure-gated fixed membership ------------------------------------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGatedRelationIsFixedWhereItsClosureSaysSo(): void
    {
        $replace = $this->writeJsonApi('PATCH', '/api/playlists/2/relationships/gatedTracks', ['data' => [$this->track('5')]]);
        $this->assertMembershipChangeProhibited($replace, 'gatedTracks');

        $add = $this->writeJsonApi('POST', '/api/playlists/2/relationships/gatedTracks', ['data' => [$this->track('5')]]);
        $add->assertStatus(403);
        self::assertSame('ADDITION_PROHIBITED', $add->json('errors.0.code'));

        self::assertSame(['1'], $this->memberIds(2));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGatedRelationChangesFreelyWhereItsClosureDoesNot(): void
    {
        // Playlist 3 is private, so its members may change.
        $this->writeJsonApi('PATCH', '/api/playlists/3/relationships/gatedTracks', ['data' => [$this->track('5')]])->assertOk();
        $this->writeJsonApi('POST', '/api/playlists/3/relationships/gatedTracks', ['data' => [$this->track('2')]])->assertOk();
        $this->writeJsonApi('DELETE', '/api/playlists/3/relationships/gatedTracks', ['data' => [$this->track('5')]])->assertOk();

        self::assertSame(['2'], $this->memberIds(3));
    }

    // --- helpers ---------------------------------------------------------------------

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertMembershipChangeProhibited(TestResponse $response, string $relationship): void
    {
        $response->assertStatus(403);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/' . $relationship, $response->json('errors.0.source.pointer'));
    }

    /**
     * @return array{type: string, id: string}
     */
    protected function track(string $id): array
    {
        return ['type' => 'tracks', 'id' => $id];
    }

    /**
     * @param list<array<string, string>> $members
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function patchPlaylist(string $id, string $relationship, array $members): TestResponse
    {
        return $this->writeJsonApi('PATCH', '/api/playlists/' . $id, ['data' => [
            'type' => 'playlists',
            'id' => $id,
            'attributes' => ['title' => 'Renamed'],
            'relationships' => [$relationship => ['data' => $members]],
        ]]);
    }

    /**
     * The sorted ids of the members a playlist's `tracks` join holds.
     *
     * @return list<string>
     */
    protected function memberIds(int $playlist): array
    {
        return $this->linkageIds($this->getJsonApi('/api/playlists/' . $playlist . '/relationships/tracks'));
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
    protected function getJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function linkageIds(TestResponse $response): array
    {
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        $ids = \array_values(\array_unique(\array_map(static fn(array $row): string => $row['id'], $data)));
        \sort($ids);

        return $ids;
    }
}
