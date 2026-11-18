<?php

declare(strict_types=1);

namespace Workbench\App\TypedMeta;

/**
 * The in-memory twin of {@see \Workbench\App\Models\TmCard}.
 */
final class TmCard
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public bool $done = false,
        public ?TmBoard $board = null,
    ) {}
}
