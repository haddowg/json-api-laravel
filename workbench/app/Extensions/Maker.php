<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

final class Maker
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
