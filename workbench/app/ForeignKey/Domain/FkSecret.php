<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

/**
 * The in-memory twin of {@see \Workbench\App\ForeignKey\Models\FkSecret}, which no scope narrows.
 */
final class FkSecret
{
    public function __construct(
        public string $id = '',
        public string $label = '',
        public ?string $owner = null,
        public bool $hidden = false,
    ) {}
}
