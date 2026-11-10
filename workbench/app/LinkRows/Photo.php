<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * A photo on the in-memory witness.
 */
final class Photo
{
    public function __construct(
        public string $id = '',
        public string $caption = '',
    ) {}
}
