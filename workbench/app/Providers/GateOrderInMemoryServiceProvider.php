<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Workbench\App\GateOrder\GateListener;
use Workbench\App\GateOrder\GateWidget;
use Workbench\App\GateOrder\GateWidgetResource;
use Workbench\App\GateOrder\PokeGateWidget;

/**
 * The gate-order suite on the in-memory witness. Widget 1 "mine" and 3 "secret" belong to
 * `user`, 2 "theirs" and 4 "child" to `other`; widget 1's partner is 2, and 4 is a child of 2.
 */
final class GateOrderInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GateWidgetResource::class, PokeGateWidget::class]);

        $theirs = new GateWidget('2', 'theirs', 'other');
        $child = new GateWidget('4', 'child', 'other');
        $theirs->children = [$child];
        $widgets = new InMemoryDataProvider(
            'gateWidgets',
            [
                '1' => new GateWidget('1', 'mine', 'user', $theirs),
                '2' => $theirs,
                '3' => new GateWidget('3', 'secret', 'user'),
                '4' => $child,
            ],
            identify: static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            },
            assignId: static function (object $item, string $id): void {
                Accessor::set($item, 'id', $id);
            },
        );
        JsonApi::provider($widgets);
        $resolver = static fn(string $type, string $id): ?object => $type === 'gateWidgets' ? $widgets->store()->find($id) : null;
        JsonApi::persister(new InMemoryDataPersister('gateWidgets', $widgets->store(), static fn(): GateWidget => new GateWidget(), $resolver));
    }

    public function boot(Dispatcher $events): void
    {
        GateListener::listen($events);
    }
}
