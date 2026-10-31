<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\Fixtures;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * `GET /playlists/{id}/relationships/orderedTracks` on the Eloquent provider: the pivot
 * `belongsToMany` relationship endpoint pages at the relation's default size (2), honours the
 * pivot `?sort`/`?filter` the related endpoint does, and renders each member's `meta.pivot`
 * from the same query that fetched the page.
 *
 * The seeded playlist holds tracks 1 and 2 at positions 1 and 2; this suite adds track 3 at
 * position 3 (weight 5) so the default page has a next page. The join's `added_at` is stored
 * as ISO-8601 text here; the workbench pivot does not cast it, so the provider casts it and it
 * renders in the `addedAt` field's format.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotRelationshipEndpointTest extends Orchestra
{
    use PinsCatalogClock;

    use SchemaConformanceTrait;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ENDPOINT = '/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/orderedTracks';

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

        DB::table('mc_playlist_track')->insert([
            'playlist_id' => Fixtures::PLAYLIST_ONE,
            'track_id' => 3,
            'position' => 3,
            'weight' => 5,
            'added_at' => '2024-01-03 00:00:00',
        ]);

        foreach ([1, 2, 3] as $track) {
            DB::table('mc_playlist_track')
                ->where('playlist_id', Fixtures::PLAYLIST_ONE)
                ->where('track_id', $track)
                ->update(['added_at' => \sprintf('2024-01-0%dT00:00:00+00:00', $track)]);
        }
    }

    public function test_a_plain_read_serves_the_first_page_at_the_relation_default_size(): void
    {
        $response = $this->fetch(self::ENDPOINT)->assertOk();

        self::assertSame(['1', '2'], $this->linkageIds($response));
        $this->assertPivotMeta($response);

        $links = $this->links($response);
        self::assertStringEndsWith('/relationships/orderedTracks?page%5Bnumber%5D=1&page%5Bsize%5D=2', $links['first'] ?? '');
        self::assertStringEndsWith('/relationships/orderedTracks?page%5Bnumber%5D=2&page%5Bsize%5D=2', $links['next'] ?? '');
        self::assertArrayNotHasKey('prev', $links);
        // orderedTracks is not countable, so the page carries no total and no `last`.
        self::assertArrayNotHasKey('last', $links);

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    public function test_page_size_slices_the_linkage(): void
    {
        $response = $this->fetch(self::ENDPOINT . '?page[number]=2&page[size]=1')->assertOk();

        self::assertSame(['2'], $this->linkageIds($response));
        $this->assertPivotMeta($response);

        $links = $this->links($response);
        self::assertStringEndsWith('?page%5Bnumber%5D=1&page%5Bsize%5D=1', $links['prev'] ?? '');
        self::assertStringEndsWith('?page%5Bnumber%5D=3&page%5Bsize%5D=1', $links['next'] ?? '');

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    public function test_a_pivot_column_sort_orders_the_linkage_and_rides_the_page_links(): void
    {
        $response = $this->fetch(self::ENDPOINT . '?sort=-position')->assertOk();

        self::assertSame(['3', '2'], $this->linkageIds($response));
        $this->assertPivotMeta($response);
        self::assertStringEndsWith('?sort=-position&page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($response)['next'] ?? '');

        $next = $this->fetch(self::ENDPOINT . '?sort=-position&page[number]=2')->assertOk();
        self::assertSame(['1'], $this->linkageIds($next));
        $this->assertPivotMeta($next);

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    public function test_a_pivot_column_filter_narrows_the_linkage(): void
    {
        $response = $this->fetch(self::ENDPOINT . '?filter[weight]=5')->assertOk();

        self::assertSame(['3'], $this->linkageIds($response));
        $this->assertPivotMeta($response);
        self::assertArrayNotHasKey('next', $this->links($response));

        $this->fetch(self::ENDPOINT . '?filter[position]=2')->assertOk()->assertJsonPath('data.0.id', '2');

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    public function test_the_related_endpoint_honours_the_same_pivot_sort_and_filter(): void
    {
        $sorted = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/orderedTracks?sort=-position')->assertOk();
        self::assertSame(['3', '2'], $this->linkageIds($sorted));
        $this->assertPivotMeta($sorted);

        $filtered = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/orderedTracks?filter[weight]=5')->assertOk();
        self::assertSame(['3'], $this->linkageIds($filtered));
    }

    public function test_the_page_and_its_pivot_meta_come_from_one_query(): void
    {
        foreach ([self::ENDPOINT, self::ENDPOINT . '?page[size]=3', self::ENDPOINT . '?sort=-position&filter[weight]=5'] as $uri) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->fetch($uri)->assertOk();
            $queries = \array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $this->assertPivotMeta($response);

            // One query loads the parent, one the page with its pivot columns, whatever the
            // page size: the pivot meta needs no query of its own, per member or otherwise.
            self::assertCount(2, $queries, $uri . "\n" . \implode("\n", $queries));
            self::assertCount(1, \array_filter($queries, static fn(string $sql): bool => \str_contains($sql, 'mc_playlist_track')));
        }
    }

    public function test_an_uncast_stored_date_renders_in_the_fields_format(): void
    {
        DB::table('mc_playlist_track')->where('playlist_id', Fixtures::PLAYLIST_ONE)->update(['added_at' => '2024-01-01 00:00:00']);

        $response = $this->fetch(self::ENDPOINT)->assertOk();

        self::assertSame('2024-01-01T00:00:00+00:00', $response->json('data.0.meta.pivot.addedAt'));
        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    /**
     * Asserts every member of the page carries the `meta.pivot` its join row stores.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertPivotMeta(TestResponse $response): void
    {
        $expected = [
            '1' => ['position' => 1, 'weight' => 1, 'addedAt' => '2024-01-01T00:00:00+00:00'],
            '2' => ['position' => 2, 'weight' => 2, 'addedAt' => '2024-01-02T00:00:00+00:00'],
            '3' => ['position' => 3, 'weight' => 5, 'addedAt' => '2024-01-03T00:00:00+00:00'],
        ];

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertNotSame([], $data);
        foreach ($data as $member) {
            self::assertIsArray($member);
            $id = $member['id'] ?? null;
            self::assertIsString($id);
            self::assertSame($expected[$id], \data_get($member, 'meta.pivot'), 'meta.pivot of track ' . $id);
        }
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function linkageIds(TestResponse $response): array
    {
        $ids = [];
        foreach ((array) $response->json('data') as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $ids[] = $member['id'];
        }

        return $ids;
    }

    /**
     * The top-level link hrefs, keyed by name.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, string>
     */
    private function links(TestResponse $response): array
    {
        $links = [];
        foreach ((array) $response->json('links') as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            if (\is_string($href)) {
                $links[(string) $name] = $href;
            }
        }

        return $links;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
