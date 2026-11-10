<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `crates` and `records` over the store named by `pm.store`: `eloquent` or `memory`.
 * Record 6 is hidden, by a global scope on Eloquent and by {@see VisibleRecordsOnly} in memory.
 *
 * On Eloquent each link row has an id of its own that differs from the record it links, so a
 * check that read link rows as members would compare the wrong ids: crate 1 links records 2, 3,
 * 4 and the hidden 6 by rows 1, 2, 3 and 5, and crate 2 links record 5 by row 4. In memory the
 * crate holds the records themselves.
 *
 * @internal
 */
final class PivotMembershipServiceProvider extends ServiceProvider
{
    /**
     * Each record: id, title and whether it is hidden.
     */
    public const array RECORDS = [
        [1, 'One', false],
        [2, 'Two', false],
        [3, 'Three', false],
        [4, 'Four', false],
        [5, 'Five', false],
        [6, 'Hidden', true],
    ];

    /**
     * Each crate: id, name and its link rows as row id and record id.
     */
    public const array CRATES = [
        [1, 'Full', [[1, 2], [2, 3], [3, 4], [5, 6]]],
        [2, 'Single', [[4, 5]]],
    ];

    public function register(): void
    {
        JsonApi::register([CrateResource::class, RecordResource::class]);

        if (config('pm.store') === 'memory') {
            $records = [];
            foreach (self::RECORDS as [$id, $title, $hidden]) {
                $records[(string) $id] = new RecordRow((string) $id, $title, $hidden);
            }

            $crates = [];
            foreach (self::CRATES as [$id, $name, $links]) {
                $crates[(string) $id] = new CrateRow(
                    (string) $id,
                    $name,
                    \array_map(static fn(array $link): RecordRow => $records[(string) $link[1]] ?? new RecordRow(), $links),
                );
            }

            /** @var InMemorySnapshotCoordinator $coordinator */
            $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);
            $crateProvider = new InMemoryDataProvider('crates', $crates, identify: static fn(object $row): string => $row instanceof CrateRow ? $row->id : '', coordinator: $coordinator);
            $this->app->instance('pm.crates', $crateProvider);
            JsonApi::provider($crateProvider);
            JsonApi::persister(new InMemoryDataPersister(
                'crates',
                $crateProvider->store(),
                static fn(): CrateRow => new CrateRow(),
                static fn(string $type, string $id): ?RecordRow => $records[$id] ?? null,
            ));
            JsonApi::provider(new VisibleRecordsOnly(new InMemoryDataProvider('records', $records, identify: static fn(object $row): string => $row instanceof RecordRow ? $row->id : '')));

            return;
        }

        $models = ['crates' => PmCrate::class, 'records' => PmRecord::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
