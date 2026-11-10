<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A shelf on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Shelf}.
 */
final class Shelf
{
    /**
     * @param list<Book> $books
     * @param list<Book> $favourites
     * @param list<Book> $picks
     * @param list<Book> $classics
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $books = [],
        public array $favourites = [],
        public array $picks = [],
        public array $classics = [],
    ) {}
}
