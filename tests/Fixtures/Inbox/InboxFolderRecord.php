<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

/**
 * The in-memory twin of {@see InboxFolder}.
 */
final class InboxFolderRecord
{
    /**
     * @param list<InboxItemRecord> $items
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $items = [],
    ) {}
}
