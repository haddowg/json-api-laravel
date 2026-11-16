<?php

declare(strict_types=1);

namespace Workbench\App\GateOrder;

/**
 * The in-memory twin of {@see \Workbench\App\Models\GateWidget}.
 */
final class GateWidget
{
    /**
     * @param list<GateWidget> $children
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $owner = '',
        public ?GateWidget $partner = null,
        public array $children = [],
    ) {}
}
