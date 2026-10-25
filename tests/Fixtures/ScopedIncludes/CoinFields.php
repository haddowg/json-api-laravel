<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

/**
 * The `coins` fields. `marks` is a pivot to-many that renders its linkage on every read
 * (`withData()`), included or not, so its members are read even without `?include`.
 */
abstract class CoinFields extends AbstractResource
{
    use DiscriminatesHolding;

    public static string $type = 'coins';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label')->sortable(),
            BelongsToMany::make('marks', 'marks')
                ->withData()
                ->fields(Integer::make('position')->build()),
        ];
    }

    public function filters(): array
    {
        return [Contains::make('label')];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
