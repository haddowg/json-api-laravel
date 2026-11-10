<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * A pinned note's link row on the in-memory witness, mirroring the Eloquent morph pivot.
 */
final class Pin
{
    public function __construct(
        public int $note_id = 0,
        public int $rank = 0,
    ) {}
}
