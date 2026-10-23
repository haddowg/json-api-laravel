<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

/**
 * The in-memory twin of {@see Mark}.
 */
final class MarkRecord
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $hidden = false,
    ) {}
}
