<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Queue;
use Workbench\App\Models\Song;
use Workbench\App\Positioned\QueueResource;
use Workbench\App\Positioned\SongResource;

/**
 * The Eloquent half of the positioned relation conformance wiring.
 */
final class PositionedEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([QueueResource::class, SongResource::class]);

        $modelByType = ['queues' => Queue::class, 'songs' => Song::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
