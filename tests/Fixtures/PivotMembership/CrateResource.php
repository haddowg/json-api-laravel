<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A crate whose records are fixed: a pivot relation that names its link-row class with
 * `through()` and has no `extractUsing()`, read off a `belongsToMany` with a custom pivot.
 */
final class CrateResource extends AbstractResource
{
    public static string $type = 'crates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('records', 'records')
                ->through(CrateRecord::class)
                ->fields(Integer::make('slot')->nullable())
                ->cannotChangeMembership(),
        ];
    }
}
