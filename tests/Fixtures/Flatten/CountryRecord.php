<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

/**
 * The in-memory twin of {@see Country}.
 */
final class CountryRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
