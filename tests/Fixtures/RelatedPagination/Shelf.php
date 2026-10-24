<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination;

final class Shelf
{
    /**
     * @param list<Book> $books
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $books = [],
    ) {}
}
