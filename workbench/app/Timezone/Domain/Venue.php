<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Domain;

final class Venue
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?\DateTimeImmutable $opened_at = null,
    ) {}
}
