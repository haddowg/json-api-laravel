<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * The in-memory arm of the music-catalog smoke suite: the SAME full domain served over
 * seeded {@see \haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider}s carrying the
 * fixtures as a POPO object graph — the dual-provider witness against the Eloquent arm.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryMusicCatalogSmokeTest extends MusicCatalogSmokeTestCase
{
    use PinsCatalogClock;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogInMemoryServiceProvider::class,
        ];
    }

    public function test_the_ordered_tracks_linkage_page_conforms_to_the_document(): void
    {
        $response = $this->fetch('/api/playlists/' . Fixtures::PLAYLIST_ONE . '/relationships/orderedTracks?page[size]=1')->assertOk();

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'orderedTracks');
    }

    public function test_pivot_sorts_and_filters_are_unrecognised_without_a_pivot_store(): void
    {
        // The in-memory provider stores no pivot columns, so it cannot order or narrow by
        // them: a pivot key is a 400 on both the relationship and the related endpoint.
        foreach (['relationships/orderedTracks', 'orderedTracks'] as $path) {
            $uri = '/api/playlists/' . Fixtures::PLAYLIST_ONE . '/' . $path;
            $this->fetch($uri . '?sort=position')->assertStatus(400)->assertJsonPath('errors.0.code', 'SORTING_UNRECOGNIZED');
            $this->fetch($uri . '?filter[weight]=1')->assertStatus(400)->assertJsonPath('errors.0.code', 'FILTERING_UNRECOGNIZED');
        }
    }
}
