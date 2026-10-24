<?php

declare(strict_types=1);

namespace Workbench\App\SingularNames;

final class Item
{
    /**
     * @param list<Item> $tags
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $tags = [],
    ) {}
}
