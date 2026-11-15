<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

/**
 * The in-memory twin of {@see \Workbench\App\Models\TxWidget}.
 */
final class TxWidget
{
    /**
     * @param list<TxNote> $notes
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $notes = [],
    ) {}
}
