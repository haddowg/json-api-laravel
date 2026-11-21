<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\LinkRows\Board;

/**
 * Serves `journals` from in-memory boards.
 *
 * @internal
 */
final class ScopedConversionsInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([JournalResource::class, RecordJournal::class, ReviseJournal::class]);

        $provider = new InMemoryDataProvider(
            'journals',
            ['1' => new Board('1', 'Weekend')],
            identify: static fn(object $board): string => $board instanceof Board ? $board->id : '',
            assignId: static function (object $board, string $id): void {
                \assert($board instanceof Board);
                $board->id = $id;
            },
        );
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('journals', $provider->store(), static fn(): Board => new Board()));
    }
}
