<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Async\AsyncAlbumsPersister;
use Workbench\App\Async\CompleteJobAction;
use Workbench\App\Async\JobSerializer;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Surface\AlbumResource;
use Workbench\App\Surface\ArtistResource;

/**
 * The **Eloquent** half of the async-write wiring (ADR 0020): the SAME resources, job
 * serializer and completion action as {@see AsyncInMemoryServiceProvider}, over the
 * reference {@see EloquentDataProvider}/{@see EloquentDataPersister} pair at `-128` —
 * with the {@see AsyncAlbumsPersister} registered at the default priority `0`, so it
 * shadows the reference persister for `albums` writes (reads and the async `PATCH`'s
 * target load still run through the Eloquent provider). `artists` writes fall through
 * to the reference persister, staying synchronous for the atomic-rejection rollback
 * assertion.
 */
final class AsyncEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, ArtistResource::class, JobSerializer::class, CompleteJobAction::class]);

        $modelByType = [
            'albums' => Album::class,
            'artists' => Artist::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);

        // Every `albums` write is accepted for async processing (never committed); the
        // default priority shadows the `-128` reference persister for this one type.
        JsonApi::persister(new AsyncAlbumsPersister());
    }
}
