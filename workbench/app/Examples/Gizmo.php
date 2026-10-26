<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

final class Gizmo
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $colour = '',
    ) {}
}
