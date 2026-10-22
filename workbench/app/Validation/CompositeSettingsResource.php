<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Shape;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A second type over the composite witness's backing, carrying one object-shaped free-form
 * map: `settings` must be a JSON object, so `{}` is valid and a JSON array is not. Kept
 * apart from `composites` so that type's rendered attributes stay as they were.
 */
#[AsJsonApiResource]
final class CompositeSettingsResource extends AbstractResource
{
    public static string $type = 'composite-settings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            ArrayHash::make('settings')->nullable()->maxProperties(5)->constrain(Shape::allOf(Schema::ofType('object'))),
        ];
    }
}
