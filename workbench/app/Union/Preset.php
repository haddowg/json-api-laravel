<?php

declare(strict_types=1);

namespace Workbench\App\Union;

/**
 * The plain model behind the in-memory `presets` type. A union property takes whatever
 * PHP type the selected member casts to.
 */
final class Preset
{
    /**
     * @param array<string, mixed>|null $extra
     */
    public function __construct(
        public string $id = '',
        public mixed $value = null,
        public mixed $label = null,
        public mixed $mode = null,
        public mixed $due = null,
        public mixed $at = null,
        public ?array $extra = null,
    ) {}
}
