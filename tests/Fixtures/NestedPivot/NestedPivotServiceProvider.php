<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Rooms hold shelves, each shelf points at a crate and an alternative crate, and crates hold
 * records through a join with pivot columns. `npBins` is a writable view of the crates. Served
 * by Eloquent, or with `nested_pivot.store` set to `memory`, by in-memory providers holding the
 * same graph and its pivot rows, or set to `custom`, by the same in-memory providers with the
 * crates and records served through {@see NpPivotStoreProvider}, which holds the crates' pivot
 * rows.
 *
 * Room 1 holds shelves 1 and 2 and lists both crates as its polymorphic `things`, and names
 * them by stored pairs as its `pinned`. Each shelf names its crate by a stored pair as `tagged`. Shelf 1's
 * crate and polymorphic holder are crate 1 and its alternative crate is 2; shelf 2's crate and
 * holder are crate 2 and it has no alternative. Crate 1 holds records 1 and 2, crate 2 holds records 1
 * and 3, so record 1 sits in both crates with a different pivot row in each. The `Np*` actions
 * answer with crates, shelves and bins built outside the include walk.
 *
 * @internal
 */
final class NestedPivotServiceProvider extends ServiceProvider
{
    /**
     * @var list<array{int, int, int, string}> crate, record, position, added at
     */
    public const array LINKS = [
        [1, 1, 1, '2026-01-01 10:00:00'],
        [1, 2, 2, '2026-01-02 10:00:00'],
        [2, 1, 5, '2026-02-01 10:00:00'],
        [2, 3, 6, '2026-02-02 10:00:00'],
    ];

    /**
     * @var array<int, string>
     */
    public const array CRATES = [1 => 'Alpha', 2 => 'Beta'];

    /**
     * @var array<int, string>
     */
    public const array RECORDS = [1 => 'One', 2 => 'Two', 3 => 'Three'];

    public function register(): void
    {
        JsonApi::register([
            NpRoomResource::class,
            NpShelfResource::class,
            NpCrateResource::class,
            NpRecordResource::class,
            NpBinResource::class,
            NpMusterCrate::class,
            NpMusterShelf::class,
            NpMusterRoom::class,
            NpMusterBin::class,
            NpRollCrates::class,
            NpMixBinsAndCrates::class,
            NpQueueCrate::class,
        ]);

        $store = config('nested_pivot.store');
        if ($store === 'memory' || $store === 'custom') {
            $identify = static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            };

            $records = [];
            foreach (self::RECORDS as $id => $title) {
                $records[(string) $id] = (object) ['id' => (string) $id, 'title' => $title, 'crates' => [], 'placedIn' => []];
            }
            $crates = [];
            foreach (self::CRATES as $id => $title) {
                $crates[(string) $id] = (object) ['id' => (string) $id, 'title' => $title, 'records' => []];
            }
            foreach (self::LINKS as [$crate, $record]) {
                $crates[(string) $crate]->records[] = $records[(string) $record];
                $records[(string) $record]->crates[] = $crates[(string) $crate];
                $records[(string) $record]->placedIn[] = $crates[(string) $crate];
            }
            $shelves = [
                '1' => (object) ['id' => '1', 'name' => 'Top', 'crate_id' => '1', 'crate' => $crates['1'], 'pinnedCrate' => $crates['1'], 'altCrate' => $crates['2'], 'holder' => $crates['1']],
                '2' => (object) ['id' => '2', 'name' => 'Bottom', 'crate_id' => '2', 'crate' => $crates['2'], 'pinnedCrate' => $crates['2'], 'altCrate' => null, 'holder' => $crates['2']],
            ];
            $rooms = ['1' => (object) ['id' => '1', 'name' => 'Den', 'shelves' => [$shelves['1'], $shelves['2']], 'things' => [$crates['1'], $crates['2']]]];

            JsonApi::provider(new InMemoryDataProvider('npRooms', $rooms, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('npShelves', $shelves, identify: $identify));
            $crateProvider = new InMemoryDataProvider('npCrates', $crates, identify: $identify);
            JsonApi::provider($store === 'custom' ? new NpPivotStoreProvider($crateProvider) : $crateProvider);
            $recordProvider = new InMemoryDataProvider('npRecords', $records, identify: $identify);
            JsonApi::provider($store === 'custom' ? new NpPivotStoreProvider($recordProvider) : $recordProvider);
            $bins = new InMemoryDataProvider('npBins', $crates, identify: $identify, assignId: static function (object $bin, string $id): void {
                $bin->id = $id;
            });
            JsonApi::provider($bins);
            // The pivot rows live with the parents. A crate is held by the crates and the bins
            // stores alike, so both hold its rows, as one join serves both types.
            $crateRows = [];
            $recordRows = [];
            foreach (self::LINKS as [$crate, $record, $position, $addedAt]) {
                $crateRows[(string) $crate][(string) $record] = ['position' => $position, 'added_at' => new \DateTimeImmutable($addedAt, new \DateTimeZone('UTC'))];
                $recordRows[(string) $record][(string) $crate] = ['position' => $position];
            }
            foreach ($crateRows as $id => $rows) {
                $crateProvider->store()->writePivotRows($crates[$id], 'records', $rows);
                $bins->store()->writePivotRows($crates[$id], 'records', $rows);
            }
            foreach ($recordRows as $id => $rows) {
                $recordProvider->store()->writePivotRows($records[$id], 'crates', $rows);
            }

            JsonApi::persister(new InMemoryDataPersister(
                'npBins',
                $bins->store(),
                static fn(): object => (object) ['id' => null, 'title' => null, 'records' => []],
                static fn(string $type, string $id): ?object => $type === 'npRecords' ? ($records[$id] ?? null) : null,
            ));

            return;
        }

        // `nested_pivot.batch_only` serves one type through a provider that reads pivot rows
        // only through the batched pivot SPI.
        $batchOnly = config('nested_pivot.batch_only');
        if ($batchOnly === 'npCrates' || $batchOnly === 'npRecords') {
            JsonApi::provider(new NpBatchOnlyProvider(new EloquentDataProvider([$batchOnly => $batchOnly === 'npCrates' ? NpCrate::class : NpRecord::class])));
        }

        JsonApi::provider(new EloquentDataProvider([
            'npRooms' => NpRoom::class,
            'npShelves' => NpShelf::class,
            'npCrates' => NpCrate::class,
            'npRecords' => NpRecord::class,
            'npBins' => NpCrate::class,
        ]), priority: -128);
        JsonApi::persister(new EloquentDataPersister(['npBins' => NpCrate::class]));
    }

    /**
     * Creates and seeds the tables the Eloquent store reads.
     */
    public static function migrate(): void
    {
        $key = static function (Blueprint $table, string $column): ColumnDefinition {
            return match (NpKeys::kind()) {
                'uuid', 'object', 'nocase' => $table->string($column, 36),
                'binary' => $table->binary($column, 16, fixed: true),
                default => $table->unsignedInteger($column),
            };
        };

        Schema::create('np_rooms', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('np_shelves', static function (Blueprint $table) use ($key): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('room_id');
            $key($table, 'crate_id');
            $key($table, 'alt_crate_id')->nullable();
            $table->string('holder_type')->nullable();
            $key($table, 'holder_id')->nullable();
        });
        Schema::create('np_crates', static function (Blueprint $table) use ($key): void {
            if (NpKeys::stringKeys()) {
                $key($table, 'id')->primary();
            } else {
                $table->increments('id');
            }
            $table->string('title');
        });
        // Join rows and the records' keys compare without case, as SQLite's NOCASE does.
        $caseless = static fn(ColumnDefinition $column): ColumnDefinition => NpKeys::kind() === 'nocase' ? $column->collation('NOCASE') : $column;
        Schema::create('np_records', static function (Blueprint $table) use ($key, $caseless): void {
            $caseless($key($table, 'id'))->primary();
            $table->string('title');
        });
        Schema::create('np_crate_record', static function (Blueprint $table) use ($key, $caseless): void {
            $caseless($key($table, 'crate_id'));
            $caseless($key($table, 'record_id'));
            $table->integer('position');
            $table->dateTime('added_at')->nullable();
        });

        DB::table('np_rooms')->insert(['id' => 1, 'name' => 'Den']);
        DB::table('np_shelves')->insert([
            ['id' => 1, 'name' => 'Top', 'room_id' => 1, 'crate_id' => NpKeys::stored('c', 1), 'alt_crate_id' => NpKeys::stored('c', 2), 'holder_type' => NpCrate::class, 'holder_id' => NpKeys::stored('c', 1)],
            ['id' => 2, 'name' => 'Bottom', 'room_id' => 1, 'crate_id' => NpKeys::stored('c', 2), 'alt_crate_id' => null, 'holder_type' => NpCrate::class, 'holder_id' => NpKeys::stored('c', 2)],
        ]);
        foreach (self::CRATES as $id => $title) {
            DB::table('np_crates')->insert(['id' => NpKeys::stored('c', $id), 'title' => $title]);
        }
        foreach (self::RECORDS as $id => $title) {
            DB::table('np_records')->insert(['id' => NpKeys::stored('r', $id), 'title' => $title]);
        }
        foreach (self::LINKS as [$crate, $record, $position, $addedAt]) {
            DB::table('np_crate_record')->insert(['crate_id' => NpKeys::joined('c', $crate), 'record_id' => NpKeys::joined('r', $record), 'position' => $position, 'added_at' => $addedAt]);
        }
    }
}
