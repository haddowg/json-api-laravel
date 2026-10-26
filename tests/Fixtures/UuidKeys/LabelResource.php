<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

final class LabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [
            UuidKeys::id(),
            Str::make('name')->sortable(),
            BelongsToMany::make('books', 'books'),
        ];
    }

    public function filters(): array
    {
        return [Contains::make('name')];
    }
}
