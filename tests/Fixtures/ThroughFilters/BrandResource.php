<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A brand, keyed by a UUID.
 */
final class BrandResource extends AbstractResource
{
    public static string $type = 'brands';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name'),
        ];
    }
}
