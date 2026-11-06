<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A type declaring OpenAPI vendor extensions: a type-wide set, per-operation sets for
 * create (overriding one type-wide key) and delete, and sets keyed by core operation name
 * for fetchOne and the designer relation's endpoints.
 */
#[AsJsonApiResource(
    extensions: ['x-feature' => 'gadgets', 'x-stability' => 'beta'],
    operationExtensions: [
        'Create' => ['x-stability' => 'stable', 'x-rate-limit' => ['per' => 'minute', 'limit' => 10]],
        'Delete' => ['x-danger' => true],
        'fetchOne' => ['x-cache' => ['ttl' => 300], 'x-mcp' => ['name' => 'show_gadget', 'description' => 'One gadget by id.']],
        'fetchRelated.designer' => ['x-mcp' => false, 'x-stability' => 'experimental'],
        'fetchRelationship.designer' => ['x-audit' => 'linkage'],
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
            BelongsTo::make('designer', 'makers'),
        ];
    }
}
