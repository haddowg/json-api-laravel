<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

/**
 * The in-memory twin of {@see Brand}.
 */
final class BrandRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
