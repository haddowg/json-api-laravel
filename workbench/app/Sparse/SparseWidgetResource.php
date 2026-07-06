<?php

declare(strict_types=1);

namespace Workbench\App\Sparse;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The sparse-by-default witness resource, shared by both providers — the Laravel twin of
 * the Symfony bundle's `SparseWidgetResource`. Its `expensiveScore` attribute is marked
 * {@see \haddowg\JsonApi\Resource\Field\AbstractField::sparseByDefault()}: omitted from
 * the default response and rendered **only** when the client names it in a
 * `fields[sparseWidgets]` member — the opt-in inverse of the usual sparse-fieldset rule
 * (present unless excluded), and orthogonal to `hidden()` / `writeOnly()`. It stays a
 * fully declared member, so naming it in `fields[sparseWidgets]` is accepted rather than
 * rejected. `storedAs('expensive_score')` reads the value off both the in-memory POPO's
 * property and the Eloquent column, so the same declaration witnesses core's
 * sparse-by-default field tier (core ADR 0117) end-to-end over HTTP on both providers.
 * Read-only: the witness only fetches, so no persister is wired on either arm.
 */
#[AsJsonApiResource(readOnly: true)]
final class SparseWidgetResource extends AbstractResource
{
    public static string $type = 'sparseWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Integer::make('expensiveScore')->storedAs('expensive_score')->sparseByDefault(),
        ];
    }
}
