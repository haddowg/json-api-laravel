<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Async\AsyncAlbumsPersister;
use Workbench\App\Async\CompleteJobAction;
use Workbench\App\Async\JobSerializer;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Artist;
use Workbench\App\Surface\AlbumResource;
use Workbench\App\Surface\ArtistResource;

/**
 * The **in-memory** half of the async-write wiring (ADR 0020): `albums` writes route to
 * the {@see AsyncAlbumsPersister}, which accepts every create/update for asynchronous
 * processing instead of committing — so `POST`/`PATCH /albums` render a `202 Accepted`.
 * Reads still run over the seeded {@see InMemoryDataProvider} (the async `PATCH` loads
 * its target through it), the standalone {@see JobSerializer} renders the `202` job
 * body, and the collection-scope {@see CompleteJobAction} drives the `303 See Other`
 * completion leg. `artists` stays synchronously writable so the atomic-rejection case
 * can prove a sync sub-operation rolls back when a later async accept fails the batch.
 */
final class AsyncInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, ArtistResource::class, JobSerializer::class, CompleteJobAction::class]);

        // One shared coordinator so an atomic batch that writes the artists store captures
        // + restores it in a single pass (the rejection case asserts the rollback).
        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        // The same baseline the surface wirings seed (artists 1/2, album 1), the album's
        // `artist` pointing at the SAME instance the artists store holds.
        $radiohead = new Artist(id: '1', name: 'Radiohead', slug: 'radiohead');
        $portishead = new Artist(id: '2', name: 'Portishead', slug: 'portishead');

        $artists = new InMemoryDataProvider('artists', ['1' => $radiohead, '2' => $portishead], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($artists);
        JsonApi::persister(new InMemoryDataPersister('artists', $artists->store(), static fn(): Artist => new Artist()));

        $albums = new InMemoryDataProvider('albums', ['1' => new Album(id: '1', title: 'OK Computer', status: 'draft', artist: $radiohead)], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($albums);

        // Every `albums` write is accepted for async processing (never committed).
        JsonApi::persister(new AsyncAlbumsPersister());
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
