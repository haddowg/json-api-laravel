<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

final class Widget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Vendor $vendor = null,
    ) {}
}
