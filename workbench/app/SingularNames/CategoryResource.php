<?php

declare(strict_types=1);

namespace Workbench\App\SingularNames;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Names its singular both ways, so the getSingularName() hook wins over the attribute.
 */
#[AsJsonApiResource(singular: 'attribute noun')]
final class CategoryResource extends AbstractResource
{
    public static string $type = 'categories';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function getSingularName(): string
    {
        return 'product category';
    }
}
