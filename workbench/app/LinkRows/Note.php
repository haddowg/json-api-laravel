<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * A note on the in-memory witness.
 */
final class Note
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
