<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * An artist created without a `slug` gets one derived from its name, unique among artists, so
 * the singular `filter[slug]` still finds exactly that artist. An empty slug is a `422` on
 * create and update, so no write can store one. Eloquent only: the in-memory arm serves
 * `artists` read-only.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentArtistSlugTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
        ];
    }

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
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new McCatalogSeeder())->run();
    }

    public function test_artists_created_without_a_slug_are_each_found_by_their_derived_slug(): void
    {
        $massive = $this->createArtist(['name' => 'Massive Attack']);
        $tricky = $this->createArtist(['name' => 'Tricky']);

        self::assertSame('massive-attack', $massive['slug']);
        self::assertSame('tricky', $tricky['slug']);
        self::assertSame($massive['id'], $this->artistIdBySlug('massive-attack'));
        self::assertSame($tricky['id'], $this->artistIdBySlug('tricky'));
    }

    public function test_a_derived_slug_already_taken_gets_a_numeric_suffix(): void
    {
        // The seed already holds `radiohead`.
        $first = $this->createArtist(['name' => 'Radiohead']);
        $second = $this->createArtist(['name' => 'Radiohead']);

        self::assertSame('radiohead-2', $first['slug']);
        self::assertSame('radiohead-3', $second['slug']);
        self::assertSame('1', $this->artistIdBySlug('radiohead'));
        self::assertSame($first['id'], $this->artistIdBySlug('radiohead-2'));
        self::assertSame($second['id'], $this->artistIdBySlug('radiohead-3'));
    }

    public function test_artists_created_in_one_atomic_batch_get_distinct_slugs(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Goldfrapp']]],
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Goldfrapp']]],
        ]]);

        $response = $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], $body);

        $response->assertOk();
        self::assertSame(
            ['goldfrapp', 'goldfrapp-2'],
            [$response->json('atomic:results.0.data.attributes.slug'), $response->json('atomic:results.1.data.attributes.slug')],
        );
    }

    public function test_a_supplied_slug_is_kept(): void
    {
        $artist = $this->createArtist(['name' => 'Portishead Tribute', 'slug' => 'dummy']);

        self::assertSame('dummy', $artist['slug']);
        self::assertSame($artist['id'], $this->artistIdBySlug('dummy'));
    }

    public function test_an_empty_slug_is_rejected_on_create_and_update(): void
    {
        $this->assertEmptySlugRejected($this->write('POST', '/api/artists', [
            'data' => ['type' => 'artists', 'attributes' => ['name' => 'Air', 'slug' => '']],
        ]));

        $this->assertEmptySlugRejected($this->write('PATCH', '/api/artists/1', [
            'data' => ['type' => 'artists', 'id' => '1', 'attributes' => ['slug' => '']],
        ]));

        // The stored slug is untouched, so the lookup still finds the artist.
        self::assertSame('1', $this->artistIdBySlug('radiohead'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertEmptySlugRejected(TestResponse $response): void
    {
        $response->assertStatus(422);
        $errors = $response->json('errors');
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        $response->assertJsonPath('errors.0.source', ['pointer' => '/data/attributes/slug']);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{id: string, slug: mixed}
     */
    private function createArtist(array $attributes): array
    {
        $response = $this->write('POST', '/api/artists', [
            'data' => ['type' => 'artists', 'attributes' => $attributes],
        ])->assertStatus(201);

        $id = $response->json('data.id');
        self::assertIsString($id);

        return ['id' => $id, 'slug' => $response->json('data.attributes.slug')];
    }

    private function artistIdBySlug(string $slug): ?string
    {
        $response = $this->get('/api/artists?filter[slug]=' . $slug, ['Accept' => self::MEDIA_TYPE])->assertOk();
        $id = $response->json('data.id');

        return \is_string($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode($body));
    }
}
