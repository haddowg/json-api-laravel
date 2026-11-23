<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DateMessage;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The date message suite, on Eloquent or the in-memory witness by `date_message.store`.
 * Booking 1 starts at 09:00Z on 1 October 2026, is due on 24 December and breaks at 12:30.
 * Booking 2 starts at 15:00Z on 2 October, is due on 31 December and breaks at 14:00.
 */
final class DateMessageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([DmBookingResource::class]);

        if (config('date_message.store') === 'memory') {
            $identify = static function (object $row): string {
                $id = Accessor::get($row, 'id');

                return \is_scalar($id) ? (string) $id : '';
            };
            $provider = new InMemoryDataProvider(
                'dmBookings',
                [
                    '1' => new DmBookingRecord('1', new \DateTimeImmutable('2026-10-01T09:00:00Z'), day: new \DateTimeImmutable('2026-10-01'), dueOn: new \DateTimeImmutable('2026-12-24'), breakAt: new \DateTimeImmutable('12:30:00')),
                    '2' => new DmBookingRecord('2', new \DateTimeImmutable('2026-10-02T15:00:00Z'), day: new \DateTimeImmutable('2026-10-02'), dueOn: new \DateTimeImmutable('2026-12-31'), breakAt: new \DateTimeImmutable('14:00:00')),
                ],
                identify: $identify,
                assignId: static function (object $row, string $id): void {
                    Accessor::set($row, 'id', $id);
                },
            );
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister('dmBookings', $provider->store(), static fn(): DmBookingRecord => new DmBookingRecord()));

            return;
        }

        $models = ['dmBookings' => DmBooking::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }

    public static function migrate(): void
    {
        Schema::create('dm_bookings', static function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('startsAt')->nullable();
            $table->dateTime('endsAt')->nullable();
            $table->date('day')->nullable();
            $table->date('dueOn')->nullable();
            $table->time('opensAt')->nullable();
            $table->time('closesAt')->nullable();
            $table->time('breakAt')->nullable();
            $table->json('window')->nullable();
            $table->string('until')->nullable();
            $table->json('marks')->nullable();
        });
        DB::table('dm_bookings')->insert([
            ['id' => 1, 'startsAt' => '2026-10-01 09:00:00', 'day' => '2026-10-01', 'dueOn' => '2026-12-24', 'breakAt' => '12:30:00'],
            ['id' => 2, 'startsAt' => '2026-10-02 15:00:00', 'day' => '2026-10-02', 'dueOn' => '2026-12-31', 'breakAt' => '14:00:00'],
        ]);
    }
}
