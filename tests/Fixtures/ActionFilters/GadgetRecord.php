<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

final class GadgetRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $colour = '',
        public int $weight = 0,
        public string $size = '',
    ) {}
}
