<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

/**
 * The in-memory twin of {@see \Workbench\App\ForeignKey\Models\FkNote}.
 */
final class FkNote
{
    public function __construct(
        public string $id = '',
        public string $body = '',
        public string $owner = '',
        public bool $archived = false,
    ) {}
}
