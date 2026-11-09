<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

/**
 * The in-memory twin of {@see Book}.
 */
final class BookRecord
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $genre = '',
        public int $pages = 0,
    ) {}
}
