<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Board;

/**
 * Serves `journals` from the link row boards on the Eloquent provider.
 *
 * @internal
 */
final class ScopedConversionsEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([JournalResource::class, RecordJournal::class, ReviseJournal::class]);

        JsonApi::provider(new EloquentDataProvider(['journals' => Board::class]));
        JsonApi::persister(new EloquentDataPersister(['journals' => Board::class]));
    }
}
