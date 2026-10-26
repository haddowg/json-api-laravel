<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\ActionSafety\InspectLampAction;
use Workbench\App\ActionSafety\Lamp;
use Workbench\App\ActionSafety\LampResource;
use Workbench\App\ActionSafety\ResetLampsAction;
use Workbench\App\ActionSafety\ToggleLampAction;

/**
 * Registers the action safety witnesses: {@see LampResource} and the actions mounted on it,
 * over an in-memory store.
 */
final class ActionSafetyInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([LampResource::class, InspectLampAction::class, ResetLampsAction::class, ToggleLampAction::class]);

        JsonApi::provider(new InMemoryDataProvider(
            'lamps',
            ['1' => new Lamp(id: '1', name: 'Anglepoise')],
            identify: static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            },
        ));
    }
}
