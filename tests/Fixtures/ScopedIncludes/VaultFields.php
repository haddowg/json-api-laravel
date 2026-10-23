<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `vaults` fields shared by the default-server and named-server registrations. `coins` is
 * a lazy, countable to-many whose related type hides rows.
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
            BelongsTo::make('featuredCoin', 'coins'),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
