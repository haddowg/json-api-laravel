<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class NpRecordResource extends AbstractResource
{
    public static string $type = 'npRecords';

    public function fields(): array
    {
        return [
            NpKeys::id('r'),
            Str::make('title'),
            BelongsToMany::make('crates', 'npCrates'),
            BelongsToMany::make('placedIn', 'npCrates')->storedAs('crates')->fields(Integer::make('position')),
        ];
    }
}
