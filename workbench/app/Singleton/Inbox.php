<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

/**
 * The in-memory twin of {@see \Workbench\App\Models\Inbox}.
 */
final class Inbox
{
    /**
     * @param list<InboxItem>  $items
     * @param list<InboxLabel> $labels
     */
    public function __construct(
        public string $id = '',
        public int $ownerId = 0,
        public string $title = '',
        public array $items = [],
        public array $labels = [],
    ) {}
}
