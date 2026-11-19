<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DecimalColumn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * @internal
 */
final class PriceResource extends AbstractResource
{
    public static string $type = 'prices';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('price')->nullable(),
            Decimal::make('rate')->nullable(),
        ];
    }
}
