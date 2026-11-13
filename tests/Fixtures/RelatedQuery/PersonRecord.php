<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

final class PersonRecord
{
    /**
     * @param list<BookRecord> $books
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?PersonRecord $mentor = null,
        public array $books = [],
    ) {}
}
