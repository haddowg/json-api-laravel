<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Links for a server whose routes live under the `api` prefix and whose `base_uri` is
 * empty, against both data layers. Every link kind carries the request origin
 * plus the prefix once: resource, relationship and related links, included resources,
 * document `self`, pagination, and the `Location` of a create.
 */
abstract class PrefixedServerLinksConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

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

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', '');
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
        ]);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('links')]
    public function resourceRelationshipIncludedAndDocumentLinksCarryThePrefix(): void
    {
        $response = $this->jsonApi()->withInclude('artist')->get('/api/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'http://localhost/api/albums/1?include=artist');
        $response->assertJsonPath('data.links.self.href', 'http://localhost/api/albums/1');
        $response->assertJsonPath('data.relationships.artist.links.self.href', 'http://localhost/api/albums/1/relationships/artist');
        $response->assertJsonPath('data.relationships.artist.links.related.href', 'http://localhost/api/albums/1/artist');
        $response->assertJsonPath('included.0.type', 'artists');
        $response->assertJsonPath('included.0.links.self.href', 'http://localhost/api/artists/1');
    }

    #[Test]
    #[Group('links')]
    public function paginationLinksCarryThePrefixOnce(): void
    {
        $response = $this->jsonApi()->withSort('title')->withPage(['number' => '1', 'size' => '1'])->get('/api/albums');

        $response->assertOk();
        $id = $response->json('data.0.id');
        self::assertIsString($id);
        $response->assertJsonPath('data.0.links.self.href', 'http://localhost/api/albums/' . $id);

        foreach (['self', 'first', 'next', 'last'] as $member) {
            $link = $response->json('links.' . $member . '.href');
            self::assertIsString($link, $member);
            self::assertStringStartsWith('http://localhost/api/albums?', $link, $member);
        }
    }

    #[Test]
    #[Group('links')]
    public function relatedAndRelationshipEndpointsCarryThePrefix(): void
    {
        $related = $this->jsonApi()->get('/api/albums/1/artist');
        $related->assertOk();
        $related->assertJsonPath('links.self.href', 'http://localhost/api/albums/1/artist');
        $related->assertJsonPath('data.links.self.href', 'http://localhost/api/artists/1');

        $relationship = $this->jsonApi()->get('/api/albums/1/relationships/artist');
        $relationship->assertOk();
        $relationship->assertJsonPath('links.self.href', 'http://localhost/api/albums/1/relationships/artist');
        $relationship->assertJsonPath('links.related.href', 'http://localhost/api/albums/1/artist');
    }

    #[Test]
    #[Group('links')]
    public function linksFollowTheRequestHost(): void
    {
        $response = $this->jsonApi()->get('https://tenant.example/api/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'https://tenant.example/api/albums/1');
        $response->assertJsonPath('data.links.self.href', 'https://tenant.example/api/albums/1');
    }

    #[Test]
    #[Group('links')]
    public function theLocationOfACreatedResourceCarriesThePrefix(): void
    {
        $response = $this->writeJsonApi('/api/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'A Brand New Album',
                    'status' => 'released',
                    'releasedAt' => '2020-02-02T00:00:00+00:00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertHeader('Location', 'http://localhost/api/albums/3');
        $response->assertJsonPath('data.links.self.href', 'http://localhost/api/albums/3');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $uri, array $document): \Illuminate\Testing\TestResponse
    {
        return $this->jsonApi()->withDocument($document)->post($uri);
    }
}
