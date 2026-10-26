<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

final class Vendor
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
