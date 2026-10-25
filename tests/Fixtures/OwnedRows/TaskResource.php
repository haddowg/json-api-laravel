<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

final class TaskResource extends AbstractResource
{
    public static string $type = 'tasks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
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
