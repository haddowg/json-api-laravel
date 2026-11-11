<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * A shelf whose `books` drop the default of `title`, a books filter with no default.
 */
final class MisdeclaredShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('books', 'books')->withoutFilterDefaults('title'),
        ];
    }
}
