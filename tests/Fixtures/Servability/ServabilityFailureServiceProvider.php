<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Servability;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;

/**
 * Wires the servability failure-path fixture: the deliberately-broken
 * {@see BadColumnAlbumResource} backed by the real `albums` Eloquent model, so the
 * {@see \haddowg\JsonApiLaravel\Server\ServableResourceWarmer}'s Eloquent column /
 * relation-method guards have a real table + model to resolve against.
 */
final class ServabilityFailureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([BadColumnAlbumResource::class]);
        JsonApi::provider(new EloquentDataProvider(['bad_albums' => Album::class]), priority: -128);
    }
}
