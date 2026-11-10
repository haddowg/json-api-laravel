<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A book on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Book}.
 */
final class Book
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public int $year = 0,
        public string $genre = '',
    ) {}
}
