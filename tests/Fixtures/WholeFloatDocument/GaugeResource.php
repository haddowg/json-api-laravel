<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WholeFloatDocument;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * @internal
 */
final class GaugeResource extends AbstractResource
{
    public static string $type = 'gauges';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('price')->nullable()->exclusiveMin(0.0)->example(5.0),
        ];
    }
}
