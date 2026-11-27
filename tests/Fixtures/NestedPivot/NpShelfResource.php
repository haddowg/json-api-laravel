<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class NpShelfResource extends AbstractResource
{
    public static string $type = 'npShelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('crate', 'npCrates'),
            BelongsTo::make('altCrate', 'npCrates'),
        ];
    }
}
