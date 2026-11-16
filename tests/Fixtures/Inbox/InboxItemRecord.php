<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

/**
 * The in-memory twin of {@see InboxItem}.
 */
final class InboxItemRecord
{
    /**
     * @param list<InboxLinkRecord> $links
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $subject_type = null,
        public ?string $subject_id = null,
        public ?\DateTimeImmutable $read_ts = null,
        public ?\DateTimeImmutable $archived_ts = null,
        public array $links = [],
    ) {}
}
