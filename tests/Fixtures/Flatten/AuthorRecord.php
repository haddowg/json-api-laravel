<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

/**
 * The in-memory twin of {@see Author}.
 */
final class AuthorRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?CountryRecord $country = null,
    ) {}
}
