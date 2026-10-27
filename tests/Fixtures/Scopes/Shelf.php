<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

final class Shelf
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Book $book = null,
    ) {}
}
