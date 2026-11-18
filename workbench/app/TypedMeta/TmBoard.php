<?php

declare(strict_types=1);

namespace Workbench\App\TypedMeta;

/**
 * The in-memory twin of {@see \Workbench\App\Models\TmBoard}.
 */
final class TmBoard
{
    /**
     * @param list<TmCard> $cards
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $cards = [],
        public ?TmCard $pinned = null,
    ) {}
}
