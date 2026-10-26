<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Str;

final class WriterResource extends AbstractResource
{
    public static string $type = 'writers';

    public function fields(): array
    {
        return [
            UuidKeys::id(),
            Str::make('name')->sortable(),
            HasMany::make('books', 'books')->countable(),
        ];
    }
}
