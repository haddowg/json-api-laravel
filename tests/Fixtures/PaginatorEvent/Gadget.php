<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PaginatorEvent;

final class Gadget
{
    public function __construct(
        public string $id = '',
        public string $slug = '',
    ) {}
}
