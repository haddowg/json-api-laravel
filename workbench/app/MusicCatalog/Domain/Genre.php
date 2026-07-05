<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A genre — a plain mutable domain object with a client-supplied natural-key id.
 */
final class Genre
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
