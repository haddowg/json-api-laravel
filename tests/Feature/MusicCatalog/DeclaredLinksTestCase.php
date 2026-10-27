<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * The catalogue's declared links on both provider arms: a track's `preview` link object,
 * left out for a track with no duration, and an album's bare `store` link. They render on
 * every read that renders the resource, including the pivot related endpoint, where the
 * pivot serializer decorator has to pass them through. The OpenAPI document names both
 * links, shows them in the operation examples, and is stamped with contract 14.
 *
 * @internal
 */
#[CoversNothing]
abstract class DeclaredLinksTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;
    use PinsCatalogClock;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array PREVIEW_ONE = [
        'href' => 'https://cdn.example.com/previews/1.mp3',
        'type' => 'audio/mpeg',
        'meta' => ['format' => 'mp3', 'expiresAt' => '2026-01-01T12:00:00+00:00'],
    ];

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
    }

    public function test_fetch_one_renders_the_declared_links(): void
    {
        $track = $this->fetch('/api/tracks/1')->assertOk();
        self::assertSame(self::PREVIEW_ONE, $track->json('data.links.preview'));
        self::assertIsArray($track->json('data.links.self'));

        $album = $this->fetch('/api/albums/1')->assertOk();
        self::assertSame('https://store.example.com/albums/1', $album->json('data.links.store'));
    }

    public function test_fetch_collection_renders_each_resources_links(): void
    {
        self::assertSame([
            '1' => self::PREVIEW_ONE['href'],
            '2' => 'https://cdn.example.com/previews/2.mp3',
            '3' => 'https://cdn.example.com/previews/3.mp3',
        ], $this->hrefsById($this->fetch('/api/tracks')->assertOk(), 'preview'));

        $stores = $this->hrefsById($this->fetch('/api/albums')->assertOk(), 'store');
        self::assertNotSame([], $stores);
        foreach ($stores as $id => $href) {
            self::assertSame('https://store.example.com/albums/' . $id, $href);
        }
    }

    public function test_included_resources_carry_their_declared_links(): void
    {
        $response = $this->fetch('/api/tracks/1?include=album')->assertOk();
        self::assertSame('https://store.example.com/albums/1', $this->at($this->included($response, 'albums', '1'), 'links', 'store'));

        $response = $this->fetch('/api/albums/1?include=tracks')->assertOk();
        self::assertSame(self::PREVIEW_ONE, $this->at($this->included($response, 'tracks', '1'), 'links', 'preview'));
    }

    public function test_the_pivot_related_endpoint_keeps_the_declared_links(): void
    {
        $previews = $this->hrefsById($this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/orderedTracks')->assertOk(), 'preview');
        \ksort($previews);

        self::assertSame([
            '1' => self::PREVIEW_ONE['href'],
            '2' => 'https://cdn.example.com/previews/2.mp3',
        ], $previews);
    }

    public function test_a_null_href_leaves_the_link_out(): void
    {
        $this->clearDuration('3');

        $track = $this->fetch('/api/tracks/3')->assertOk();
        self::assertIsArray($track->json('data.links.self'));
        self::assertArrayNotHasKey('preview', (array) $track->json('data.links'));

        $tracks = $this->fetch('/api/tracks')->assertOk();
        self::assertSame('https://cdn.example.com/previews/1.mp3', $tracks->json('data.0.links.preview.href'));
        self::assertSame('3', $tracks->json('data.2.id'));
        self::assertArrayNotHasKey('preview', (array) $tracks->json('data.2.links'));
    }

    public function test_the_document_describes_the_declared_links(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        // The catalogue declares OAuth scopes, which stamp the scoped contract tier.
        self::assertSame(16, $this->at($doc, 'info', 'x-generator', 'contract'));

        $preview = $this->arrayAt($doc, 'components', 'schemas', 'TracksResourceLinks', 'properties', 'preview');
        self::assertSame('A short audio preview of the track, valid until expiresAt.', $preview['description'] ?? null);
        self::assertSame(['href', 'type', 'meta'], $preview['required'] ?? null);
        self::assertSame('audio/mpeg', $this->at($preview, 'properties', 'type', 'const'));
        $meta = $this->arrayAt($preview, 'properties', 'meta');
        self::assertSame(['format', 'expiresAt'], $meta['required'] ?? null);
        self::assertSame('Audio encoding of the preview.', $this->at($meta, 'properties', 'format', 'description'));
        self::assertSame('date-time', $this->at($meta, 'properties', 'expiresAt', 'format'));
        self::assertSame(self::PREVIEW_ONE, $preview['example'] ?? null);

        $store = $this->arrayAt($doc, 'components', 'schemas', 'AlbumsResourceLinks', 'properties', 'store');
        self::assertSame("The album's page in the store.", $store['description'] ?? null);
        self::assertSame('https://store.example.com/albums/1', $store['example'] ?? null);
        self::assertNotContains('store', (array) ($this->arrayAt($doc, 'components', 'schemas', 'AlbumsResourceLinks')['required'] ?? []));

        $example = fn(string $path): mixed => $this->at($doc, 'paths', $path, 'get', 'responses', '200', 'content', self::MEDIA_TYPE, 'example');
        self::assertSame(self::PREVIEW_ONE, $this->at($example('/tracks/{id}'), 'data', 'links', 'preview'));
        self::assertSame(self::PREVIEW_ONE, $this->at($example('/tracks'), 'data', 0, 'links', 'preview'));
        self::assertSame('https://store.example.com/albums/1', $this->at($example('/albums/{id}'), 'data', 'links', 'store'));
    }

    /**
     * Sets a seeded track's duration to zero, so its preview link resolves to no href.
     */
    abstract protected function clearDuration(string $id): void;

    /**
     * The href of the named link on each resource in `data`, keyed by id; `null` when a
     * resource doesn't carry it.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function hrefsById(TestResponse $response, string $link): array
    {
        $hrefs = [];
        foreach ($this->arrayAt($response->json(), 'data') as $resource) {
            $links = $this->arrayAt($resource, 'links');
            $value = $links[$link] ?? null;
            $hrefs[$this->stringAt($resource, 'id')] = \is_array($value) ? ($value['href'] ?? null) : $value;
        }

        return $hrefs;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<mixed>
     */
    private function included(TestResponse $response, string $type, string $id): array
    {
        foreach ((array) $response->json('included') as $resource) {
            if (\is_array($resource) && $resource['type'] === $type && $resource['id'] === $id) {
                return $resource;
            }
        }
        self::fail(\sprintf('No included %s %s.', $type, $id));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
