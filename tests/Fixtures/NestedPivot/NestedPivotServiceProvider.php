<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Rooms hold shelves, each shelf points at a crate and an alternative crate, and crates hold
 * records through a join with pivot columns. Served by Eloquent, or with `nested_pivot.store`
 * set to `memory`, by in-memory providers holding the same graph and no pivot rows.
 *
 * Room 1 holds shelves 1 and 2. Shelf 1's crate is 1 and its alternative crate is 2; shelf 2's
 * crate is 2 and it has no alternative. Crate 1 holds records 1 and 2, crate 2 holds records 1
 * and 3, so record 1 sits in both crates with a different pivot row in each.
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
        JsonApi::register([NpRoomResource::class, NpShelfResource::class, NpCrateResource::class, NpRecordResource::class]);

        if (config('nested_pivot.store') === 'memory') {
            $identify = static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            };

            $records = [];
            foreach (self::RECORDS as $id => $title) {
                $records[(string) $id] = (object) ['id' => (string) $id, 'title' => $title, 'crates' => []];
            }
            $crates = [];
            foreach (self::CRATES as $id => $title) {
                $crates[(string) $id] = (object) ['id' => (string) $id, 'title' => $title, 'records' => []];
            }
            foreach (self::LINKS as [$crate, $record]) {
                $crates[(string) $crate]->records[] = $records[(string) $record];
                $records[(string) $record]->crates[] = $crates[(string) $crate];
            }
            $shelves = [
                '1' => (object) ['id' => '1', 'name' => 'Top', 'crate' => $crates['1'], 'altCrate' => $crates['2']],
                '2' => (object) ['id' => '2', 'name' => 'Bottom', 'crate' => $crates['2'], 'altCrate' => null],
            ];
            $rooms = ['1' => (object) ['id' => '1', 'name' => 'Den', 'shelves' => [$shelves['1'], $shelves['2']]]];

            JsonApi::provider(new InMemoryDataProvider('npRooms', $rooms, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('npShelves', $shelves, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('npCrates', $crates, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('npRecords', $records, identify: $identify));

            return;
        }

        JsonApi::provider(new EloquentDataProvider([
            'npRooms' => NpRoom::class,
            'npShelves' => NpShelf::class,
            'npCrates' => NpCrate::class,
            'npRecords' => NpRecord::class,
        ]), priority: -128);
    }

    /**
     * Creates and seeds the tables the Eloquent store reads.
     */
    public static function migrate(): void
    {
        Schema::create('np_rooms', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('np_shelves', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('room_id');
            $table->unsignedInteger('crate_id');
            $table->unsignedInteger('alt_crate_id')->nullable();
        });
        Schema::create('np_crates', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
        });
        Schema::create('np_records', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
        });
        Schema::create('np_crate_record', static function (Blueprint $table): void {
            $table->unsignedInteger('crate_id');
            $table->unsignedInteger('record_id');
            $table->integer('position');
            $table->dateTime('added_at');
        });

        DB::table('np_rooms')->insert(['id' => 1, 'name' => 'Den']);
        DB::table('np_shelves')->insert([
            ['id' => 1, 'name' => 'Top', 'room_id' => 1, 'crate_id' => 1, 'alt_crate_id' => 2],
            ['id' => 2, 'name' => 'Bottom', 'room_id' => 1, 'crate_id' => 2, 'alt_crate_id' => null],
        ]);
        foreach (self::CRATES as $id => $title) {
            DB::table('np_crates')->insert(['id' => $id, 'title' => $title]);
        }
        foreach (self::RECORDS as $id => $title) {
            DB::table('np_records')->insert(['id' => $id, 'title' => $title]);
        }
        foreach (self::LINKS as [$crate, $record, $position, $addedAt]) {
            DB::table('np_crate_record')->insert(['crate_id' => $crate, 'record_id' => $record, 'position' => $position, 'added_at' => $addedAt]);
        }
    }
}
