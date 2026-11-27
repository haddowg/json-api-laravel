<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class NpRecordResource extends AbstractResource
{
    public static string $type = 'npRecords';

    public function fields(): array
    {
        return [Id::make(), Str::make('title'), BelongsToMany::make('crates', 'npCrates')];
    }
}
