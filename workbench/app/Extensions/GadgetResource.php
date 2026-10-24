<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A type declaring OpenAPI vendor extensions: a type-wide set, and per-operation sets for
 * create (overriding one type-wide key) and delete.
 */
#[AsJsonApiResource(
    extensions: ['x-feature' => 'gadgets', 'x-stability' => 'beta'],
    operationExtensions: [
        'Create' => ['x-stability' => 'stable', 'x-rate-limit' => ['per' => 'minute', 'limit' => 10]],
        'Delete' => ['x-danger' => true],
    ],
)]
final class GadgetResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('maker', 'makers'),
        ];
    }
}
