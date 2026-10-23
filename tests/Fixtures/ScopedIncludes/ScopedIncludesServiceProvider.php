<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the scoped-includes fixture from three config keys the test sets:
 *  - `scoped_includes.server`: `partner` registers the types on the named `partner` server
 *    only, `default` on the implicit default server;
 *  - `scoped_includes.store`: `eloquent` or `memory`;
 *  - `scoped_includes.scope`: `provider` hides rows through a {@see HidesHiddenRows} provider
 *    per scoped type (coins and marks each get their own), `global` through an Eloquent
 *    global scope on the models (Eloquent only).
 *
 * `chests` carries a polymorphic to-many over both scoped types.
 *
 * @internal
 */
final class ScopedIncludesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $partner = config('scoped_includes.server') === 'partner';

        JsonApi::register($partner
            ? [PartnerVaultResource::class, PartnerCoinResource::class, PartnerMarkResource::class, PartnerChestResource::class]
            : [DefaultVaultResource::class, DefaultCoinResource::class, DefaultMarkResource::class, DefaultChestResource::class]);

        if (config('scoped_includes.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $eloquent = new EloquentDataProvider([
            'vaults' => Vault::class,
            'coins' => Coin::class,
            'marks' => Mark::class,
            'chests' => Chest::class,
        ]);
        JsonApi::provider($eloquent, priority: -128);
        JsonApi::persister(new EloquentDataPersister(['vaults' => Vault::class]), priority: -128);

        if (config('scoped_includes.scope') === 'provider') {
            JsonApi::provider(new HidesHiddenRows('coins', $eloquent));
            JsonApi::provider(new HidesHiddenRows('marks', $eloquent));
        }

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    private function registerInMemory(): void
    {
        $marks = [
            '1' => new MarkRecord('1', 'm-one'),
            '2' => new MarkRecord('2', 'm-two', hidden: true),
            '3' => new MarkRecord('3', 'm-three', hidden: true),
            '4' => new MarkRecord('4', 'm-four'),
        ];
        $coins = [
            '1' => new CoinRecord('1', 'alpha', marks: [$marks['1'], $marks['2']]),
            '2' => new CoinRecord('2', 'bravo', hidden: true, marks: [$marks['1']]),
            '3' => new CoinRecord('3', 'charlie', marks: [$marks['3']]),
            '4' => new CoinRecord('4', 'delta', hidden: true, marks: [$marks['4']]),
            '5' => new CoinRecord('5', 'echo', marks: [$marks['4']]),
        ];
        $vaults = [
            '1' => new VaultRecord('1', 'North', [$coins['1'], $coins['2'], $coins['3']], $coins['1'], $coins['2']),
            '2' => new VaultRecord('2', 'South', [$coins['4'], $coins['5']], $coins['4'], $marks['1']),
        ];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        $chests = [
            '1' => new ChestRecord('1', 'Oak', [$coins['1'], $coins['2'], $marks['1'], $marks['3']]),
        ];

        $vaultProvider = new InMemoryDataProvider('vaults', $vaults, identify: $identify);
        JsonApi::provider($vaultProvider);
        JsonApi::persister(new InMemoryDataPersister('vaults', $vaultProvider->store(), static fn(): VaultRecord => new VaultRecord('', '')));
        JsonApi::provider(new InMemoryDataProvider('chests', $chests, identify: $identify));
        JsonApi::provider(new HidesHiddenRows('coins', new InMemoryDataProvider('coins', $coins, identify: $identify)));
        JsonApi::provider(new HidesHiddenRows('marks', new InMemoryDataProvider('marks', $marks, identify: $identify)));
    }
}
