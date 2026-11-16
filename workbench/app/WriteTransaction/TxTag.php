<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

/**
 * The in-memory twin of {@see \Workbench\App\Models\TxTag}.
 */
final class TxTag
{
    public function __construct(
        public string $id = '',
        public string $label = '',
    ) {}
}
