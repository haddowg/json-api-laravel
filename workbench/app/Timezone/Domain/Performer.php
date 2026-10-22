<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Domain;

final class Performer
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
