<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NamedExtensions;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Names extensions for a relation the type does not have, which only core's projection can
 * catch.
 *
 * @internal
 */
#[AsJsonApiResource(
    readOnly: true,
    operationExtensions: ['fetchRelated.owner' => ['x-mcp' => false]],
)]
final class WidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
