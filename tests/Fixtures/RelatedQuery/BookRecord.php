<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

final class BookRecord
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?PersonRecord $writer = null,
    ) {}
}
