<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;

final class TaskResource extends AbstractResource
{
    public static string $type = 'tasks';

    public function fields(): array
    {
        return [
            Id::make(),
            // A title is unique among the tasks the signed-in user can see.
            Str::make('title')->constrain(new UniqueEntity(['title'], scoped: true)),
        ];
    }

    public function isCountable(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return [Contains::make('title')];
    }
}
