<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Artist;
use Workbench\App\Models\User;
use Workbench\App\Surface\AlbumResource;
use Workbench\App\Surface\ArtistResource;
use Workbench\App\Surface\PublishAlbumAction;
use Workbench\App\Surface\PurgeAlbumsAction;

/**
 * The **in-memory** half of the Phase-4 surface wiring (custom actions + Atomic Operations):
 * it registers the writable {@see AlbumResource} / {@see ArtistResource} and the
 * {@see PublishAlbumAction}, over in-memory providers/persisters sharing one cross-store
 * {@see InMemorySnapshotCoordinator} so an atomic batch spanning both types rolls back
 * identity-coherently. Because the action + atomic loop are provider-agnostic, the SAME
 * assertions run here and on the Eloquent half ({@see SurfaceEloquentServiceProvider}).
 */
final class SurfaceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, ArtistResource::class, PublishAlbumAction::class, PurgeAlbumsAction::class]);

        // One shared coordinator so a batch that writes both stores captures + restores them
        // in a single pass (cross-store object identity survives a rollback).
        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        // Build the seed graph once so the album's `artist` is the SAME Artist instance the
        // artists store holds (the in-memory analogue of the FK).
        $radiohead = new Artist(id: '1', name: 'Radiohead', slug: 'radiohead');
        $portishead = new Artist(id: '2', name: 'Portishead', slug: 'portishead');

        $artists = new InMemoryDataProvider('artists', ['1' => $radiohead, '2' => $portishead], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($artists);
        JsonApi::persister(new InMemoryDataPersister('artists', $artists->store(), static fn(): Artist => new Artist()));

        $albums = new InMemoryDataProvider('albums', ['1' => new Album(id: '1', title: 'OK Computer', status: 'draft', artist: $radiohead)], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($albums);

        // The album persister's related-object resolver reads the artists STORE (not the seed
        // array), so an artist created earlier in the same atomic batch resolves as the
        // album's linked `artist`.
        $resolver = static function (string $type, string $id) use ($artists): ?object {
            return $type === 'artists' ? $artists->store()->find($id) : null;
        };
        JsonApi::persister(new InMemoryDataPersister('albums', $albums->store(), static fn(): Album => new Album(), $resolver));
    }

    public function boot(): void
    {
        // The `publish` ability the PublishAlbumAction gates on (PLAN decision 12): a
        // write-capable user may publish; a read-only user is denied (a 403). A null user
        // (guest) is denied. Provider-agnostic — the album argument is the in-memory POPO here.
        Gate::define('publish', static fn(?User $user, object $album): bool => $user?->can_write === true);

        // The `purge` ability the collection-scope PurgeAlbumsAction gates on: authorized
        // against the resource-class token (no instance in a collection-scope action), so
        // only an admin passes. This proves the collection-scope gate is enforced rather
        // than fail-open when the type declares no dedicated policy class.
        Gate::define('purge', static fn(?User $user): bool => $user?->is_admin === true);
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
