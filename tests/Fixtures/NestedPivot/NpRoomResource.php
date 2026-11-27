<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class NpRoomResource extends AbstractResource
{
    public static string $type = 'npRooms';

    public function fields(): array
    {
        return [Id::make(), Str::make('name'), HasMany::make('shelves', 'npShelves')];
    }
}
