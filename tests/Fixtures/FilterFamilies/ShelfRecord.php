<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

/**
 * The in-memory twin of {@see Shelf}.
 */
final class ShelfRecord
{
    /**
     * @param list<BookRecord> $books
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $books = [],
    ) {}
}
