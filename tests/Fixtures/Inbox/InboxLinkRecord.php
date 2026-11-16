<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

/**
 * The in-memory twin of {@see InboxLink}.
 */
final class InboxLinkRecord
{
    public function __construct(
        public string $linked_type,
        public string $linked_id,
    ) {}
}
