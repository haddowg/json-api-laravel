<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

final class Gadget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Maker $maker = null,
    ) {}
}
