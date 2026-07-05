<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdPattern;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A read-only type whose Id declares the `numeric()` route pattern (core ADR 0038): the
 * `{id}` route requirement must be composed to accept only digits, so a non-numeric id 404s
 * at routing — matching the `idPattern` the projected OpenAPI document advertises.
 */
#[AsJsonApiResource(readOnly: true)]
final class NumericThingResource extends AbstractResource
{
    public static string $type = 'numeric_things';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }
}
