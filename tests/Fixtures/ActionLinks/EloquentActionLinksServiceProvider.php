<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;
use Workbench\App\Models\User;

/**
 * The Eloquent arm of the action-link fixture: `records` over the workbench `albums`
 * table, seeded by the test with the same five rows the in-memory arm holds.
 */
final class EloquentActionLinksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RecordResource::class, ReviewRecord::class, FeatureRecord::class, ArchiveRecord::class]);
        JsonApi::provider(new EloquentDataProvider(['records' => Album::class]), priority: -128);
    }

    public function boot(): void
    {
        Gate::define('featureRecord', static fn(?User $user, object $record): bool => $user?->is_admin === true);
    }
}
