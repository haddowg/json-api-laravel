<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone\Domain;

final class Shift
{
    public function __construct(
        public string $id = '',
        public ?\DateTimeImmutable $starts_at = null,
        public ?\DateTimeImmutable $day = null,
    ) {}
}
