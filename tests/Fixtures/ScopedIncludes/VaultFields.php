<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `vaults` fields shared by the default-server and named-server registrations. `coins` is
 * a lazy, countable to-many whose related type hides rows; `featuredCoin` and the polymorphic
 * `spotlight` are to-ones onto the scoped types, `everyCoin` computes the vault's coins with
 * `extractUsing()`,, and `featuredLabel` flattens the featured
 * coin's label through `on()`.
 */
abstract class VaultFields extends AbstractResource
{
    public static string $type = 'vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            HasMany::make('coins', 'coins')->countable(),
            // The vault's coins, computed by a closure instead of read off the relation.
            HasMany::make('everyCoin', 'coins')->countable()->extractUsing(static function (mixed $vault): array {
                if ($vault instanceof Vault) {
                    return Coin::query()->where('vault_id', $vault->getKey())->orderBy('id')->get()->all();
                }

                return $vault instanceof VaultRecord ? $vault->coins : [];
            }),
            BelongsTo::make('featuredCoin', 'coins'),
            Str::make('featuredLabel')->storedAs('label')->on('featuredCoin'),
            MorphTo::make('spotlight', ['coins', 'marks']),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
