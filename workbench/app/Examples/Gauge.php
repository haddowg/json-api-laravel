<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

final class Gauge
{
    public function __construct(
        public string $id = '',
        public int $reading = 0,
    ) {}
}
