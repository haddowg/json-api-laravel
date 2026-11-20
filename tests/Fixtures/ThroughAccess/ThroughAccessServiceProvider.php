<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the crates domain over the store named by `through.store`: `eloquent`, where parcels
 * and tags carry global scopes, or `memory`, where scoping providers serve them.
 *
 * Seed: crate 1 holds parcels 1 (user 1, `apple`, tagged `red`) and 2 (user 2, `banana`, tagged
 * the secret `hidden`), crate 2 holds parcel 3 (user 1, `cherry`, tagged `hidden`), crate 3 none.
 * Crate 1 sits in vault `v1`, safe `s1`, ledger and auditor `l1`; crate 2 in vault `v2`. Crate 1
 * is about parcel 2, crate 2 about parcel 3 and crate 3 about safe `s1`.
 *
 * @internal
 */
final class ThroughAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CrateResource::class, ParcelResource::class, TagResource::class, VaultResource::class, SafeResource::class, LedgerResource::class, TallyCratesAction::class]);

        if (config('through.store') !== 'memory') {
            $models = ['crates' => Crate::class, 'parcels' => Parcel::class, 'tags' => Tag::class, 'vaults' => Vault::class, 'safes' => Safe::class, 'ledgers' => Ledger::class];
            JsonApi::provider(new EloquentDataProvider($models), priority: -128);

            return;
        }

        $v1 = new CodedRecord('1', 'v1');
        $v2 = new CodedRecord('2', 'v2');
        $s1 = new CodedRecord('1', 's1');
        $l1 = new CodedRecord('1', 'l1');
        $red = new TagRecord('1', 'red');
        $hidden = new TagRecord('2', 'hidden', true);
        $one = new CrateRecord('1', 'alpha', [], $v1, $s1, $l1, $l1, 'parcels', '2');
        $two = new CrateRecord('2', 'beta', [], $v2, subject_type: 'parcels', subject_id: '3');
        $three = new CrateRecord('3', 'gamma', subject_type: 'safes', subject_id: '1');
        $parcels = [
            '1' => new ParcelRecord('1', '1', 'apple', $one, [$red]),
            '2' => new ParcelRecord('2', '2', 'banana', $one, [$hidden]),
            '3' => new ParcelRecord('3', '1', 'cherry', $two, [$hidden]),
        ];
        $one->parcels = [$parcels['1'], $parcels['2']];
        $two->parcels = [$parcels['3']];

        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_string($row->id) ? $row->id : '';
        JsonApi::provider(new InMemoryDataProvider('crates', ['1' => $one, '2' => $two, '3' => $three], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('vaults', ['1' => $v1, '2' => $v2], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('safes', ['1' => $s1], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('ledgers', ['1' => $l1], identify: $identify));
        JsonApi::provider(new OwnParcelsProvider($parcels));
        JsonApi::provider(new PublicTagsProvider(['1' => $red, '2' => $hidden]));
    }
}
