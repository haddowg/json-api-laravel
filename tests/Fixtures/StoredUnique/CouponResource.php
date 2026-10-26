<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StoredUnique;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;

final class CouponResource extends AbstractResource
{
    public static string $type = 'coupons';

    public function fields(): array
    {
        return [
            Id::make(),
            // A code is unique per region among active coupons, a missing region included.
            Str::make('code')->constrain(new UniqueEntity(['code', 'region'], using: 'active', errorPath: 'region', ignoreNull: false)),
            Str::make('region')->nullable(),
        ];
    }
}
