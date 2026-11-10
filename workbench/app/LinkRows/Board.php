<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * A board on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Board}.
 */
final class Board
{
    /**
     * @param list<\Workbench\App\Models\BoardItem> $boardItems
     * @param list<Pin>       $pins
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $boardItems = [],
        public array $pins = [],
    ) {}
}
