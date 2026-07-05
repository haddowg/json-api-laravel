<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A cursor group — the plain parent domain object seeded into the in-memory provider
 * for the RELATED-collection cursor (keyset) conformance suite. Its `widgets` property
 * carries the member {@see CursorWidget} POPOs (the SAME instances the widgets store
 * holds), so the {@see \Workbench\App\CursorRelated\CursorGroupResource} `widgets`
 * relation reads the parent-scoped set straight off the parent.
 */
final class CursorGroup
{
    /**
     * @param list<CursorWidget> $widgets
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $widgets = [],
    ) {}
}
