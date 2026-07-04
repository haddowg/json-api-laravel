<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A cursor widget — a plain mutable domain object seeded into the in-memory provider
 * for the cursor (keyset) conformance suite. Property names are the storage **columns**
 * the {@see \Workbench\App\Cursor\CursorWidgetResource} fields resolve to, shared with
 * the Eloquent {@see \Workbench\App\Models\CursorWidget} model so one resource
 * declaration serves both providers (blueprint §3.4/§5).
 */
final class CursorWidget
{
    public function __construct(
        public string $id = '',
        public string $category = '',
        public ?int $priority = null,
        public ?\DateTimeImmutable $released_at = null,
    ) {}
}
