<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * A note on the in-memory witness.
 */
final class Note
{
    /**
     * @param list<Board> $pinnedOn the boards that pin the note
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public array $pinnedOn = [],
    ) {}
}
