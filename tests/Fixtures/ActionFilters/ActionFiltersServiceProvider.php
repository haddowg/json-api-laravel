<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * Wires `gadgets` and its two filtered collection actions over the store named by
 * `gadgets.store`: `eloquent`, where the {@see Gadget} model hides archived rows with a global
 * scope, or `memory`, where the archived gadget is never seeded.
 *
 * Seed: 1 Anvil red 50 large, 2 Bolt blue 1 small, 3 Crank red 5 small, 4 Dynamo red 20 large
 * and archived, 5 Easel green 8 large.
 *
 * @internal
 */
final class ActionFiltersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GadgetResource::class, CountGadgetsAction::class, PaintGadgetsAction::class]);

        if (config('gadgets.store') === 'memory') {
            $gadgets = [
                '1' => new GadgetRecord('1', 'Anvil', 'red', 50, 'large'),
                '2' => new GadgetRecord('2', 'Bolt', 'blue', 1, 'small'),
                '3' => new GadgetRecord('3', 'Crank', 'red', 5, 'small'),
                '5' => new GadgetRecord('5', 'Easel', 'green', 8, 'large'),
            ];
            $provider = new InMemoryDataProvider('gadgets', $gadgets, identify: static fn(object $gadget): string => $gadget instanceof GadgetRecord ? $gadget->id : '');
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister('gadgets', $provider->store(), static fn(): GadgetRecord => new GadgetRecord()));

            return;
        }

        $models = ['gadgets' => Gadget::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }

    public function boot(): void
    {
        Gate::policy(Gadget::class, GadgetPolicy::class);
        Gate::policy(GadgetRecord::class, GadgetPolicy::class);
        Gate::define('paint-gadgets', static fn(?User $user): bool => $user?->can_write === true);
    }
}
