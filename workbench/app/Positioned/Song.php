<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

/**
 * A song on the in-memory witness.
 */
final class Song
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?int $slot = null,
    ) {}
}
