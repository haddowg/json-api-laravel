<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

/**
 * The in-memory twin of {@see \Workbench\App\ForeignKey\Models\FkItem}.
 */
final class FkItem
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?string $owner_key = null,
    ) {}
}
