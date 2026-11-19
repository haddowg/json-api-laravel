<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Board;

/**
 * Serves `tallies` from the link row boards on the Eloquent provider.
 *
 * @internal
 */
final class PivotNumbersEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TallyResource::class, ScoreTally::class, ReviseTally::class, ImportTallies::class]);

        JsonApi::provider(new EloquentDataProvider(['tallies' => Board::class]));
        JsonApi::persister(new EloquentDataPersister(['tallies' => Board::class]));
    }
}
