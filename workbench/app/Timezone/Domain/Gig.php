<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Domain;

final class Gig
{
    /**
     * @param list<Performer> $performers
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?\DateTimeImmutable $starts_at = null,
        public ?\DateTimeImmutable $day = null,
        public ?\DateTimeImmutable $doors_open = null,
        public ?Venue $venue = null,
        public array $performers = [],
    ) {}
}
