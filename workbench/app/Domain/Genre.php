<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A genre — a plain mutable domain object seeded into the in-memory provider for the
 * read-only feature suite.
 */
final class Genre
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
