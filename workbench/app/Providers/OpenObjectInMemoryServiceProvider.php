<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Validation\OpenObjectSheetResource;
use Workbench\App\Validation\Sheet;

/**
 * The **in-memory** half of the open-object conformance wiring: the
 * {@see OpenObjectSheetResource} over a writable, seeded provider and persister sharing
 * one store. The seed mirrors the Eloquent half's row so identical assertions referee
 * both providers.
 */
final class OpenObjectInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([OpenObjectSheetResource::class]);

        $sheets = new InMemoryDataProvider(
            'sheets',
            ['1' => new Sheet('1', 'Seed', Sheet::SEED_ROWS, Sheet::SEED_LABELS, Sheet::SEED_SETTINGS, Sheet::SEED_METRICS)],
            identify: static function (object $item): string {
                \assert($item instanceof Sheet);

                return $item->id;
            },
            assignId: static function (object $item, string $id): void {
                \assert($item instanceof Sheet);

                $item->id = $id;
            },
        );
        JsonApi::provider($sheets);
        JsonApi::persister(new InMemoryDataPersister('sheets', $sheets->store(), static fn(): Sheet => new Sheet()));
    }
}
