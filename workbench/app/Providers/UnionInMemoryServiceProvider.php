<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Union\Preset;
use Workbench\App\Union\PresetResource;

/**
 * The **in-memory** half of the union conformance wiring: the {@see PresetResource} over
 * a writable provider and persister sharing one store, seeded with one preset so a PATCH
 * has a target.
 */
final class UnionInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PresetResource::class]);
        $presets = new InMemoryDataProvider(
            'presets',
            ['1' => new Preset('1', 'gold', 'Seed')],
            identify: static function (object $item): string {
                \assert($item instanceof Preset);

                return $item->id;
            },
            assignId: static function (object $item, string $id): void {
                \assert($item instanceof Preset);
                $item->id = $id;
            },
        );
        JsonApi::provider($presets);
        JsonApi::persister(new InMemoryDataPersister('presets', $presets->store(), static fn(): Preset => new Preset()));
    }
}
