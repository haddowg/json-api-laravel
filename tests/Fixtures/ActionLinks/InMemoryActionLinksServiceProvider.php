<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * The in-memory arm of the action-link fixture: five records, three released and two
 * withdrawn.
 */
final class InMemoryActionLinksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RecordResource::class, ReviewRecord::class, FeatureRecord::class, ArchiveRecord::class]);
        JsonApi::provider(new InMemoryDataProvider('records', [
            '1' => new Record(1, 'First', 'released'),
            '2' => new Record(2, 'Second', 'released'),
            '3' => new Record(3, 'Third', 'withdrawn'),
            '4' => new Record(4, 'Fourth', 'released'),
            '5' => new Record(5, 'Fifth', 'withdrawn'),
        ]));
    }

    public function boot(): void
    {
        Gate::define('featureRecord', static fn(?User $user, object $record): bool => $user?->is_admin === true);
    }
}
