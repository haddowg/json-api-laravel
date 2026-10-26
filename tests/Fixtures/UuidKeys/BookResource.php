<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            UuidKeys::id()->allowClientId(),
            Str::make('title')->sortable(),
            BelongsTo::make('writer', 'writers')->nullable(),
            BelongsToMany::make('labels', 'labels')->countable(),
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
