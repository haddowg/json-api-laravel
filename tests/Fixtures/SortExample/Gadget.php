<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\SortExample;

final class Gadget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $price = 0,
    ) {}
}
