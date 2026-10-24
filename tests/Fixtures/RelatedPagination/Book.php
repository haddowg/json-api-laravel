<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination;

final class Book
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
