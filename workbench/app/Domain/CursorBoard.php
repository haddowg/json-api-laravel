<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A cursor board — the pivot-bearing parent domain object seeded into the in-memory
 * side of the pivot-cursor conformance suite. Its `widgets` property carries the member
 * {@see CursorWidget} POPOs (the SAME instances the widgets store holds) for the
 * relation read, and `positions` carries the per-member pivot values (`widget id =>
 * position`) the suite's custom provider serves as the pivot map — so `meta.pivot`
 * renders identically to the Eloquent join.
 */
final class CursorBoard
{
    /**
     * @param list<CursorWidget>          $widgets
     * @param array<int|string, int|null> $positions widget wire id => pivot `position`
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $widgets = [],
        public array $positions = [],
    ) {}
}
