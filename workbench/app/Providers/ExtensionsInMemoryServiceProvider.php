<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Extensions\Gadget;
use Workbench\App\Extensions\GadgetResource;
use Workbench\App\Extensions\InspectGadgetAction;
use Workbench\App\Extensions\Maker;
use Workbench\App\Extensions\MakerResource;
use Workbench\App\Extensions\Part;
use Workbench\App\Extensions\PartSerializer;

/**
 * Registers the vendor-extension witnesses: {@see GadgetResource} declaring type-wide and
 * per-operation extensions, the extension-free {@see MakerResource} it relates to, and the
 * {@see InspectGadgetAction} mounted on gadgets, and the standalone {@see PartSerializer}.
 */
final class ExtensionsInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GadgetResource::class, MakerResource::class, InspectGadgetAction::class, PartSerializer::class]);

        $acme = new Maker(id: '1', name: 'Acme');
        JsonApi::provider(new InMemoryDataProvider('makers', ['1' => $acme], identify: self::identify(), assignId: self::assignId()));

        $gadgets = new InMemoryDataProvider('gadgets', ['1' => new Gadget(id: '1', name: 'Sprocket', maker: $acme)], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($gadgets);
        JsonApi::persister(new InMemoryDataPersister('gadgets', $gadgets->store(), static fn(): Gadget => new Gadget()));

        JsonApi::provider(new InMemoryDataProvider('parts', ['1' => new Part(id: '1', name: 'Cog')], identify: self::identify()));
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
