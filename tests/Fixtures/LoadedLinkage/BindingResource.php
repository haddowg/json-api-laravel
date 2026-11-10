<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A binding is hidden from every read by its scoping provider when `hidden`, and by the
 * type's default filter when `detached`.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class BindingResource extends AbstractResource
{
    public static string $type = 'bindings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('template', 'templates')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('detached')->asBoolean()->default(false),
        ];
    }
}
