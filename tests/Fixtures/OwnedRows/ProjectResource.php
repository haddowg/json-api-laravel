<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class ProjectResource extends AbstractResource
{
    public static string $type = 'projects';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('tasks', 'tasks')->countable(),
        ];
    }
}
