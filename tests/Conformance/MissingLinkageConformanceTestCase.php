<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every relationship write refuses linkage to a resource that does not exist with a `404`,
 * before anything is stored, whichever persister applies it: a create, an update, each
 * relationship endpoint, to-one and to-many ({@see AtomicConformanceTestCase} covers a batch). A remove naming a missing
 * resource is refused the same way, and one naming an existing resource that is not a member
 * changes nothing. Asserted identically on the in-memory witness
 * ({@see InMemoryMissingLinkageConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentMissingLinkageConformanceTest}), over the conformance catalogue: playlist 1
 * holds tracks 1 to 4 and track 5 exists.
 */
abstract class MissingLinkageConformanceTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    protected function seedConformanceData(): void {}

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateNamingAMissingResourceIsNotFoundAndStoresNothing(): void
    {
        $albums = $this->rowCount('/api/albums');
        $playlists = $this->rowCount('/api/playlists');

        $this->assertNotFound($this->write('POST', '/api/albums', ['data' => [
            'type' => 'albums',
            'attributes' => ['title' => 'Orphan', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
            'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '999']]],
        ]]));
        $this->assertNotFound($this->write('POST', '/api/playlists', ['data' => [
            'type' => 'playlists',
            'attributes' => ['title' => 'Orphan'],
            'relationships' => ['tracks' => ['data' => [['type' => 'tracks', 'id' => '1'], ['type' => 'tracks', 'id' => '999']]]],
        ]]));

        self::assertSame($albums, $this->rowCount('/api/albums'));
        self::assertSame($playlists, $this->rowCount('/api/playlists'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anUpdateNamingAMissingResourceIsNotFoundAndChangesNothing(): void
    {
        $artist = $this->read('/api/albums/1/relationships/artist')->json('data');

        $this->assertNotFound($this->write('PATCH', '/api/albums/1', ['data' => [
            'type' => 'albums',
            'id' => '1',
            'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => '999']]],
        ]]));
        $this->assertNotFound($this->write('PATCH', '/api/playlists/1', ['data' => [
            'type' => 'playlists',
            'id' => '1',
            'relationships' => ['tracks' => ['data' => [['type' => 'tracks', 'id' => '999']]]],
        ]]));

        self::assertSame($artist, $this->read('/api/albums/1/relationships/artist')->json('data'));
        self::assertSame(['1', '2', '3', '4'], $this->trackIds());
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function theRelationshipEndpointsRefuseAMissingResourceOnEveryMode(): void
    {
        $artist = $this->read('/api/albums/1/relationships/artist')->json('data');

        $this->assertNotFound($this->write('PATCH', '/api/albums/1/relationships/artist', ['data' => ['type' => 'artists', 'id' => '999']]));
        foreach (['PATCH', 'POST', 'DELETE'] as $method) {
            $this->assertNotFound($this->write($method, '/api/playlists/1/relationships/tracks', ['data' => [['type' => 'tracks', 'id' => '2'], ['type' => 'tracks', 'id' => '999']]]));
        }

        self::assertSame($artist, $this->read('/api/albums/1/relationships/artist')->json('data'));
        self::assertSame(['1', '2', '3', '4'], $this->trackIds());
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function removingAnExistingResourceThatIsNotAMemberChangesNothing(): void
    {
        $this->write('DELETE', '/api/playlists/1/relationships/tracks', ['data' => [['type' => 'tracks', 'id' => '5']]])->assertOk();

        self::assertSame(['1', '2', '3', '4'], $this->trackIds());
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertNotFound(TestResponse $response): void
    {
        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('RESOURCE_NOT_FOUND', $response->json('errors.0.code'));
    }

    private function rowCount(string $uri): int
    {
        $data = $this->read($uri . '?page[size]=100')->json('data');
        self::assertIsArray($data);

        return \count($data);
    }

    /**
     * @return list<string>
     */
    private function trackIds(): array
    {
        $data = $this->read('/api/playlists/1/relationships/tracks?page[size]=100')->json('data');
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        $response = $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), $uri . ': ' . $response->getContent());

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->json($method, $uri, $body, ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
    }
}
