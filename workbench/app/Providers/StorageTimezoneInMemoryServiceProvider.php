<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\StorageTimezone\Domain\Shift;
use Workbench\App\StorageTimezone\ShiftFixtures;
use Workbench\App\StorageTimezone\ShiftResource;

/**
 * Wires the shifts resource to the in-memory witness, holding each shift as an instant.
 */
final class StorageTimezoneInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShiftResource::class]);

        $shifts = [];
        foreach (ShiftFixtures::rows() as $row) {
            $shifts[(string) $row['id']] = new Shift(
                (string) $row['id'],
                ShiftFixtures::at($row['starts_at']),
                ShiftFixtures::at($row['day']),
            );
        }

        $provider = new InMemoryDataProvider(
            'shifts',
            $shifts,
            identify: static function (object $item): string {
                \assert($item instanceof Shift);

                return $item->id;
            },
            assignId: static function (object $item, string $id): void {
                \assert($item instanceof Shift);

                $item->id = $id;
            },
        );

        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('shifts', $provider->store(), static fn(): Shift => new Shift()));
    }
}
