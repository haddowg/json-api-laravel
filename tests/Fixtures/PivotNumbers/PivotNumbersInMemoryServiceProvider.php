<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\LinkRows\Board;

/**
 * Serves `tallies` from in-memory boards.
 *
 * @internal
 */
final class PivotNumbersInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TallyResource::class, ScoreTally::class, ReviseTally::class, ImportTallies::class]);

        $provider = new InMemoryDataProvider(
            'tallies',
            ['1' => new Board('1', 'Weekend')],
            identify: static fn(object $board): string => $board instanceof Board ? $board->id : '',
            assignId: static function (object $board, string $id): void {
                \assert($board instanceof Board);
                $board->id = $id;
            },
        );
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('tallies', $provider->store(), static fn(): Board => new Board()));
    }
}
