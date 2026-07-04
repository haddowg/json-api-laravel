<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Genre;
use Workbench\App\Security\AlbumResource;
use Workbench\App\Security\GenreResource;
use Workbench\App\Support\Fixtures;

/**
 * The **in-memory** half of the authorization conformance wiring (PLAN decision 7): it
 * registers the secured {@see AlbumResource} (dedicated `AlbumApiPolicy`) and the
 * policy-less {@see GenreResource}, both explicitly (they live outside the scanned
 * `app/JsonApi` so they never perturb the music suites), over writable, seeded
 * in-memory providers sharing one store. Because the dedicated policy is provider-
 * agnostic, the SAME assertions run here and on the Eloquent half — the dual-provider
 * authorization bridge contract.
 */
final class SecurityInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, GenreResource::class]);

        $albums = new InMemoryDataProvider('albums', $this->albums(), identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($albums);
        JsonApi::persister(new InMemoryDataPersister('albums', $albums->store(), static fn(): Album => new Album()));

        $genres = new InMemoryDataProvider('genres', $this->genres(), identify: self::identify());
        JsonApi::provider($genres);
        JsonApi::persister(new InMemoryDataPersister('genres', $genres->store(), static fn(): Genre => new Genre()));
    }

    /**
     * @return array<int|string, Album>
     */
    private function albums(): array
    {
        $albums = [];
        foreach (Fixtures::albums() as $row) {
            $albums[(string) $row['id']] = new Album(
                id: (string) $row['id'],
                title: $row['title'],
                average_rating: $row['average_rating'],
                status: $row['status'],
                explicit: $row['explicit'],
                available_from: $row['available_from'],
                released_at: $row['released_at'],
            );
        }

        return $albums;
    }

    /**
     * @return array<string, Genre>
     */
    private function genres(): array
    {
        $genres = [];
        foreach (Fixtures::genres() as $row) {
            $genres[$row['id']] = new Genre(id: $row['id'], name: $row['name']);
        }

        return $genres;
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
