<?php

declare(strict_types=1);

namespace Workbench\App\NestedFieldsets;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The panels again as `pinboards`, whose `values` is a `notSparseField()`: it renders
 * whole whatever the fieldset lists, unless the fieldset selects keys of it.
 */
#[AsJsonApiResource]
final class PinboardResource extends AbstractResource
{
    public static string $type = 'pinboards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            Obj::make('values')->nullable()
                ->additionalProperties(Str::make('*')->nullable())
                ->nestedFieldset(pattern: '[a-z][a-z0-9_]*')
                ->notSparseField(),
        ];
    }
}
