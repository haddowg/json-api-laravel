<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentWarmer;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * Built links as link objects with a `describedby` (ADR 0037), against both data layers
 * over the music catalog, whose links are based at `https://music.example/api` and whose
 * document is served at `/docs.json`.
 *
 * Each `describedby` is checked twice: against the pointer the suite expects, and by
 * resolving it in the document `/docs.json` actually serves, so a link and the document
 * can't drift apart unnoticed.
 */
abstract class DescribedLinksConformanceTestCase extends Orchestra
{
    private const string BASE = 'https://music.example/api';

    private const string DOCUMENT = '/docs.json';

    private const string PUBLISHED = 'https://docs.music.example/{server}/openapi.json';

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    private string $cacheDir = '';

    private bool $serveDocument = true;

    private ?bool $adminLinkObjects = null;

    private bool $emptyBase = false;

    private bool $unprefixed = false;

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
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-described-links-' . \uniqid();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        CatalogConfig::apply($config);
        $config->set('app.debug', false);
        $config->set('jsonapi.openapi.expose_in_prod', $this->serveDocument);
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);

        if ($this->adminLinkObjects !== null) {
            /** @var array<string, array<string, mixed>> $servers */
            $servers = $config->get('jsonapi.servers');
            $servers['admin']['link_objects'] = $this->adminLinkObjects;
            $config->set('jsonapi.servers', $servers);
        }

        if ($this->emptyBase) {
            $config->set('jsonapi.base_uri', '');
        }

        if ($this->unprefixed) {
            /** @var array<string, array<string, mixed>> $servers */
            $servers = $config->get('jsonapi.servers');
            $servers['default']['prefix'] = '';
            $config->set('jsonapi.servers', $servers);
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function adminLinksAsStrings($app): void
    {
        $this->adminLinkObjects = false;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function linksAsStrings($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.link_objects', false);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function publishedDocument($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.document_url', self::PUBLISHED);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function unservedDocument($app): void
    {
        // A flag, not a config write, so it holds whichever environment hook runs first.
        $this->serveDocument = false;

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.expose_in_prod', false);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function emptyBaseUri($app): void
    {
        // Flags, not config writes: CatalogConfig runs after this hook and would undo them.
        $this->emptyBase = true;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function emptyBaseUriUnprefixed($app): void
    {
        $this->emptyBase = true;
        $this->unprefixed = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        $this->app?->make(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);

        parent::tearDown();
    }

    #[Test]
    #[Group('links')]
    public function aResourceAndItsDocumentLinksPointAtTheFetchOperation(): void
    {
        $document = $this->fetch('/api/albums/1');

        $self = ['href' => self::BASE . '/albums/1', 'describedby' => $this->pointer('/albums/{id}')];
        self::assertSame($self, self::at($document, 'data', 'links', 'self'));
        self::assertSame($self, self::at($document, 'links', 'self'));
        self::assertSame(['href' => $this->pointer('/albums/{id}')], self::at($document, 'links', 'describedby'));
        // The default-included artist's own link names the artist fetch.
        self::assertSame(
            ['href' => self::BASE . '/artists/1', 'describedby' => $this->pointer('/artists/{id}')],
            self::at($document, 'included', 0, 'links', 'self'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('links')]
    public function relationshipLinksPointAtTheRelationshipAndRelatedOperations(): void
    {
        $document = $this->fetch('/api/albums/1');

        self::assertSame(
            [
                'self' => [
                    'href' => self::BASE . '/albums/1/relationships/artist',
                    'describedby' => $this->pointer('/albums/{id}/relationships/artist'),
                ],
                'related' => [
                    'href' => self::BASE . '/albums/1/artist',
                    'describedby' => $this->pointer('/albums/{id}/artist'),
                ],
            ],
            self::at($document, 'data', 'relationships', 'artist', 'links'),
        );
    }

    #[Test]
    #[Group('links')]
    public function collectionPaginationLinksPointAtTheCollectionOperation(): void
    {
        $document = $this->fetch('/api/artists?page[number]=1&page[size]=1');

        $links = self::at($document, 'links');
        self::assertIsArray($links);
        foreach (['self', 'first', 'next'] as $name) {
            self::assertSame($this->pointer('/artists'), self::at($links, $name, 'describedby'), $name);
            self::assertIsString(self::at($links, $name, 'href'), $name);
        }
        $next = self::at($links, 'next', 'href');
        self::assertIsString($next);
        self::assertStringStartsWith(self::BASE . '/artists?', $next);
        self::assertSame(['href' => $this->pointer('/artists')], $links['describedby'] ?? null);

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('links')]
    public function aRelationshipEndpointsLinksPointAtTheRelationshipOperation(): void
    {
        $document = $this->fetch('/api/albums/1/relationships/tracks?page[size]=1');

        $relationship = $this->pointer('/albums/{id}/relationships/tracks');
        self::assertSame(['href' => self::BASE . '/albums/1/relationships/tracks', 'describedby' => $relationship], self::at($document, 'links', 'self'));
        self::assertSame(['href' => self::BASE . '/albums/1/tracks', 'describedby' => $this->pointer('/albums/{id}/tracks')], self::at($document, 'links', 'related'));
        self::assertSame($relationship, self::at($document, 'links', 'next', 'describedby'));
        self::assertSame(['href' => $relationship], self::at($document, 'links', 'describedby'));

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('links')]
    public function aRelatedEndpointPointsItsDocumentAtTheRelatedOperationAndItsDataAtTheRelatedType(): void
    {
        $document = $this->fetch('/api/albums/1/artist');

        self::assertSame(
            ['href' => self::BASE . '/albums/1/artist', 'describedby' => $this->pointer('/albums/{id}/artist')],
            self::at($document, 'links', 'self'),
        );
        self::assertSame(
            ['href' => self::BASE . '/artists/1', 'describedby' => $this->pointer('/artists/{id}')],
            self::at($document, 'data', 'links', 'self'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('actions')]
    public function anActionLinkPointsAtTheActionsFirstDeclaredMethod(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));

        $document = $this->fetch('/api/albums/1');

        self::assertSame(
            [
                'href' => self::BASE . '/albums/1/-actions/reissue',
                'describedby' => $this->pointer('/albums/{id}/-actions/reissue', 'post'),
            ],
            self::at($document, 'data', 'links', 'reissue'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('actions')]
    public function anAlbumLinksItsDownloadOnlyWhileReleased(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));
        self::assertSame(self::BASE . '/albums/1/-actions/download', self::at($this->fetch('/api/albums/1'), 'data', 'links', 'download', 'href'));

        $patch = $this->call('PATCH', '/api/albums/1', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['status' => 'withdrawn']]]));
        $patch->assertOk();

        $links = self::at($this->fetch('/api/albums/1'), 'data', 'links');
        self::assertIsArray($links);
        self::assertArrayNotHasKey('download', $links);
        self::assertArrayHasKey('reissue', $links);
    }

    #[Test]
    #[Group('openapi')]
    public function theDownloadLinkCarriesItsDeclaredDescription(): void
    {
        $response = $this->get(self::DOCUMENT);
        $response->assertOk();
        $description = self::at($this->decode($response), 'components', 'schemas', 'AlbumsResourceLinks', 'properties', 'download', 'description');
        self::assertIsString($description);
        self::assertStringStartsWith('A short-lived link to the album as CSV, present while the album is released.', $description);
    }

    #[Test]
    #[Group('actions')]
    public function anActionLinkUsesTheConfiguredBaseUriWhateverTheRequestHost(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));

        $document = $this->fetch('https://tenant.example/api/albums/1');

        self::assertSame(self::BASE . '/albums/1', self::at($document, 'data', 'links', 'self', 'href'));
        self::assertSame(self::BASE . '/albums/1/-actions/reissue', self::at($document, 'data', 'links', 'reissue', 'href'));
        self::assertSame(self::BASE . '/albums/1/-actions/download', self::at($document, 'data', 'links', 'download', 'href'));
    }

    #[Test]
    #[Group('actions')]
    #[DefineEnvironment('emptyBaseUri')]
    public function anActionLinkUsesTheRequestHostAndPrefixWhenTheBaseUriIsEmpty(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));

        $document = $this->fetch('https://tenant.example/api/albums/1');

        self::assertSame('https://tenant.example/api/albums/1', self::at($document, 'data', 'links', 'self', 'href'));
        self::assertSame('https://tenant.example/api/albums/1/-actions/reissue', self::at($document, 'data', 'links', 'reissue', 'href'));
        self::assertSame('https://tenant.example/api/albums/1/-actions/download', self::at($document, 'data', 'links', 'download', 'href'));
    }

    #[Test]
    #[Group('actions')]
    #[DefineEnvironment('emptyBaseUriUnprefixed')]
    public function anActionLinkUsesTheRequestHostAloneWhenUnprefixed(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));

        $document = $this->fetch('https://tenant.example/albums/1');

        self::assertSame('https://tenant.example/albums/1', self::at($document, 'data', 'links', 'self', 'href'));
        self::assertSame('https://tenant.example/albums/1/-actions/reissue', self::at($document, 'data', 'links', 'reissue', 'href'));
        self::assertSame('https://tenant.example/albums/1/-actions/download', self::at($document, 'data', 'links', 'download', 'href'));
    }

    #[Test]
    #[Group('atomic')]
    public function anAtomicResultPointsAtTheAtomicOperation(): void
    {
        $response = $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'genres', 'id' => 'trip-hop'], 'data' => ['type' => 'genres', 'id' => 'trip-hop', 'attributes' => ['name' => 'Trip hop']]],
        ]]));
        $response->assertOk();
        $document = $this->decode($response);

        self::assertSame(['href' => $this->pointer('/operations', 'post')], self::at($document, 'links', 'describedby'));
        self::assertSame(
            ['href' => self::BASE . '/genres/trip-hop', 'describedby' => $this->pointer('/genres/{id}')],
            self::at($document, 'atomic:results', 0, 'data', 'links', 'self'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('links')]
    public function anErrorDocumentCarriesNoDescribedby(): void
    {
        $response = $this->get('/api/albums/999', ['Accept' => self::MEDIA_TYPE]);
        $response->assertNotFound();

        self::assertNull(self::at($this->decode($response), 'links', 'describedby'));
    }

    #[Test]
    #[Group('openapi')]
    public function theServedDocumentDescribesTheLinksTheResponsesCarry(): void
    {
        $links = self::at($this->servedDocument(), 'components', 'schemas', 'AlbumsResourceLinks');
        self::assertIsArray($links);

        // The link carries an example, so the reference is wrapped in a single-entry allOf.
        self::assertNull(self::at($links, 'properties', 'self', '$ref'));
        self::assertSame('#/components/schemas/RenderedLink', self::at($links, 'properties', 'self', 'allOf', 0, '$ref'));
        self::assertSame($this->pointer('/albums/{id}'), self::at($links, 'properties', 'self', 'example', 'describedby'));
        // The asLink action is listed by name, described by its first method.
        self::assertSame(
            $this->pointer('/albums/{id}/-actions/reissue', 'post'),
            self::at($links, 'properties', 'reissue', 'example', 'describedby'),
        );
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('linksAsStrings')]
    public function linksRenderAsStringsWhenLinkObjectsAreTurnedOff(): void
    {
        $document = $this->fetch('/api/albums/1');

        self::assertSame(self::BASE . '/albums/1', self::at($document, 'data', 'links', 'self'));
        self::assertSame(
            ['self' => self::BASE . '/albums/1/relationships/artist', 'related' => self::BASE . '/albums/1/artist'],
            self::at($document, 'data', 'relationships', 'artist', 'links'),
        );
        self::assertSame(self::BASE . '/albums/1', self::at($document, 'links', 'self'));
        // A string can't carry a describedby, but the document's own one still renders.
        self::assertSame($this->pointer('/albums/{id}'), self::at($document, 'links', 'describedby'));

        // And the document describes strings, not link objects.
        self::assertSame('#/components/schemas/LinkObject', self::at($this->servedDocument(), 'components', 'schemas', 'AlbumsResourceLinks', 'properties', 'self', 'allOf', 0, '$ref'));
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('adminLinksAsStrings')]
    public function aServerCanTurnLinkObjectsOffForItself(): void
    {
        $admin = $this->fetch('/admin/albums/1');
        self::assertSame('https://admin.music.example/admin/albums/1', self::at($admin, 'data', 'links', 'self'));
        self::assertSame('/admin/docs.json#/paths/~1albums~1%7Bid%7D/get', self::at($admin, 'links', 'describedby'));

        $default = $this->fetch('/api/albums/1');
        self::assertSame(['href' => self::BASE . '/albums/1', 'describedby' => $this->pointer('/albums/{id}')], self::at($default, 'data', 'links', 'self'));
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('publishedDocument')]
    public function aConfiguredDocumentUrlIsUsedAsGiven(): void
    {
        $document = $this->fetch('/api/albums/1');

        $url = \str_replace('{server}', 'default', self::PUBLISHED);
        $describedby = $url . '#/paths/~1albums~1%7Bid%7D/get';
        self::assertSame(['href' => self::BASE . '/albums/1', 'describedby' => $describedby], self::at($document, 'data', 'links', 'self'));
        self::assertSame(['href' => $describedby], self::at($document, 'links', 'describedby'));

        // The fragments are the served document's, wherever it's published.
        $this->assertEveryDescribedbyResolves($document, $url);
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('unservedDocument')]
    public function linksCarryNoDescribedbyWhenNoDocumentIsServed(): void
    {
        $this->get(self::DOCUMENT)->assertNotFound();

        $document = $this->fetch('/api/albums/1');

        self::assertSame(['href' => self::BASE . '/albums/1'], self::at($document, 'data', 'links', 'self'));
        self::assertSame(['self' => ['href' => self::BASE . '/albums/1']], self::at($document, 'links'));
        \array_walk_recursive($document, static function (mixed $value, int|string $key): void {
            self::assertNotSame('describedby', $key);
        });
    }

    #[Test]
    #[Group('links')]
    public function warmedOperationsDescribeTheLinks(): void
    {
        self::assertSame([], $this->app?->make(DocumentWarmer::class)->warm());
        self::assertFileExists($this->cacheDir . '/default.operations.json');

        $document = $this->fetch('/api/albums/1');

        self::assertSame($this->pointer('/albums/{id}'), self::at($document, 'data', 'links', 'self', 'describedby'));
        $this->assertEveryDescribedbyResolves($document);
    }

    /**
     * The `describedby` the suite expects for an operation of the served document.
     */
    private function pointer(string $path, string $method = 'get'): string
    {
        return self::DOCUMENT . '#/paths/' . \str_replace(['~', '/', '{', '}'], ['~0', '~1', '%7B', '%7D'], $path) . '/' . $method;
    }

    /**
     * Walks the whole document and resolves every `describedby` it carries inside the
     * served document: each must be `$url` plus a fragment that lands on an operation.
     *
     * @param array<array-key, mixed> $document
     */
    private function assertEveryDescribedbyResolves(array $document, string $url = self::DOCUMENT): void
    {
        $served = $this->servedDocument();
        $found = 0;

        \array_walk_recursive($document, static function (mixed $value, int|string $key) use ($served, $url, &$found): void {
            if ($key !== 'describedby' && !(\is_string($value) && \str_contains($value, '#/paths/'))) {
                return;
            }
            self::assertIsString($value);
            [$documentUrl, $fragment] = \explode('#', $value, 2) + [1 => ''];
            self::assertSame($url, $documentUrl, $value);

            $node = $served;
            foreach (\array_slice(\explode('/', \rawurldecode($fragment)), 1) as $segment) {
                $segment = \str_replace(['~1', '~0'], ['/', '~'], $segment);
                self::assertIsArray($node);
                self::assertArrayHasKey($segment, $node, \sprintf('%s does not resolve in the served document', $value));
                $node = $node[$segment];
            }
            self::assertIsArray($node);
            self::assertArrayHasKey('responses', $node, \sprintf('%s does not name an operation', $value));
            ++$found;
        });

        self::assertGreaterThan(0, $found, 'the document carries no describedby');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function servedDocument(): array
    {
        $response = $this->get(self::DOCUMENT);
        $response->assertOk();

        return $this->decode($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function fetch(string $uri): array
    {
        $response = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        return $this->decode($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>
     */
    private function decode(TestResponse $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The value at `$path` inside `$value`, or null where the path runs out.
     */
    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
