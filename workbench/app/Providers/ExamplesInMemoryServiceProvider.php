<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Examples\Gauge;
use Workbench\App\Examples\GaugeSerializer;
use Workbench\App\Examples\Gizmo;
use Workbench\App\Examples\GizmoResource;
use Workbench\App\Examples\InspectWidgetAction;
use Workbench\App\Examples\RenameWidgetAction;
use Workbench\App\Examples\Vendor;
use Workbench\App\Examples\VendorResource;
use Workbench\App\Examples\Widget;
use Workbench\App\Examples\WidgetResource;

/**
 * Registers the operation-example witnesses: the fully exampled {@see WidgetResource} and
 * {@see VendorResource} with the widget actions and the standalone {@see GaugeSerializer} on
 * the `default` server, and {@see GizmoResource}, which misses an example, on `legacy`.
 */
final class ExamplesInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([
            WidgetResource::class,
            VendorResource::class,
            InspectWidgetAction::class,
            RenameWidgetAction::class,
            GaugeSerializer::class,
            GizmoResource::class,
        ]);

        $acme = new Vendor(id: '3', name: 'Acme');
        JsonApi::provider(new InMemoryDataProvider('vendors', ['3' => $acme], identify: self::identify()));

        $widgets = new InMemoryDataProvider('widgets', ['7' => new Widget(id: '7', name: 'Sprocket', vendor: $acme)], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($widgets);
        JsonApi::persister(new InMemoryDataPersister('widgets', $widgets->store(), static fn(): Widget => new Widget()));

        JsonApi::provider(new InMemoryDataProvider('gauges', ['g1' => new Gauge(id: 'g1', reading: 42)], identify: self::identify()));

        JsonApi::provider(new InMemoryDataProvider('gizmos', ['1' => new Gizmo(id: '1', name: 'Whirligig', colour: 'red')], identify: self::identify()));
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
