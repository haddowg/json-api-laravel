<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\NestedFieldsets\Curator;
use Workbench\App\NestedFieldsets\CuratorResource;
use Workbench\App\NestedFieldsets\Panel;
use Workbench\App\NestedFieldsets\PanelResource;
use Workbench\App\NestedFieldsets\PinboardResource;

/**
 * The **in-memory** half of the nested fieldsets conformance wiring: one seeded graph in
 * which panel 1 links curator 1, and a persister for each type sharing its store. The
 * seed mirrors the Eloquent half's rows so identical assertions referee both providers.
 */
final class NestedFieldsetsInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PanelResource::class, CuratorResource::class, PinboardResource::class]);

        $curator = new Curator('1', 'Ada', Curator::SEED_STATS, Curator::SEED_OFFICE, Curator::SEED_EXHIBITS);
        $curators = new InMemoryDataProvider('curators', ['1' => $curator], self::identify(...), self::assignId(...));
        $panels = new InMemoryDataProvider(
            'panels',
            ['1' => new Panel('1', 'Seed', Panel::SEED_VALUES, Panel::SEED_GROUPS, Panel::SEED_SETTINGS, $curator, Panel::SEED_ADDRESS, Panel::SEED_ROWS, [], Panel::SEED_TAGS, Panel::SEED_BLOCKS, Panel::SEED_HERO)],
            self::identify(...),
            self::assignId(...),
        );
        $pinboards = new InMemoryDataProvider(
            'pinboards',
            ['1' => new Panel('1', 'Seed', Panel::SEED_VALUES, Panel::SEED_GROUPS, Panel::SEED_SETTINGS, $curator, Panel::SEED_ADDRESS)],
            self::identify(...),
            self::assignId(...),
        );

        JsonApi::provider($curators);
        JsonApi::provider($panels);
        JsonApi::provider($pinboards);
        JsonApi::persister(new InMemoryDataPersister('curators', $curators->store(), static fn(): Curator => new Curator()));
        JsonApi::persister(new InMemoryDataPersister('panels', $panels->store(), static fn(): Panel => new Panel()));
    }

    private static function identify(object $item): string
    {
        \assert($item instanceof Panel || $item instanceof Curator);

        return $item->id;
    }

    private static function assignId(object $item, string $id): void
    {
        \assert($item instanceof Panel || $item instanceof Curator);

        $item->id = $id;
    }
}
