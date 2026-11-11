<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

final class BookRecord
{
    public function __construct(
        public string $id,
        public string $title,
        public bool $archived = false,
        public bool $draft = false,
    ) {}
}
