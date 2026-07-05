<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the write-capable standalone pair the standalone-hydrator tests drive: the
 * `beacons` type (a {@see BeaconSerializer} + {@see BeaconHydrator} — zero
 * `AbstractResource`) over a seeded in-memory provider/persister pair sharing one
 * store, plus the hydrator-only `ingest-commands` type ({@see IngestCommandHydrator},
 * no serializer — no endpoints). Everything is registered explicitly, never scanned,
 * so no other suite sees these types.
 *
 * @internal
 */
final class StandaloneHydratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([
            BeaconSerializer::class,
            BeaconHydrator::class,
            IngestCommandHydrator::class,
        ]);

        $provider = new InMemoryDataProvider(
            'beacons',
            // Seed objects are keyed by their JSON:API id (the store's map key).
            ['1' => new Beacon(id: '1', label: 'Lighthouse')],
            identify: static fn(object $beacon): string => $beacon instanceof Beacon ? (string) $beacon->id : '',
            assignId: static function (object $beacon, string $id): void {
                if ($beacon instanceof Beacon) {
                    $beacon->id = $id;
                }
            },
        );

        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('beacons', $provider->store(), static fn(): Beacon => new Beacon()));
    }
}
