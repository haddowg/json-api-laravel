<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

/**
 * The in-memory twin of {@see \Workbench\App\ForeignKey\Models\FkProfile}.
 */
final class FkProfile
{
    public function __construct(
        public string $id = '',
        public string $bio = '',
        public ?string $owner = null,
    ) {}
}
