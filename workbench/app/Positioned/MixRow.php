<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

/**
 * A mix link row on the in-memory witness.
 */
final class MixRow
{
    public function __construct(
        public int $song_id = 0,
        public ?int $position = null,
    ) {}
}
