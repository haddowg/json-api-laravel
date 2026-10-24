<?php

declare(strict_types=1);

namespace Workbench\App\SingularNames;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Names its singular on the attribute, so the generated prose reads "a catalog item".
 */
#[AsJsonApiResource(singular: ' catalog item ')]
final class ProductResource extends AbstractResource
{
    public static string $type = 'products';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('tags', 'tags'),
        ];
    }
}
