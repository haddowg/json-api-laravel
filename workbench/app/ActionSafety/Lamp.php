<?php

declare(strict_types=1);

namespace Workbench\App\ActionSafety;

/**
 * The plain object the in-memory `lamps` witness stores.
 */
final class Lamp
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {}
}
