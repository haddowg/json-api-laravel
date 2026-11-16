<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

/**
 * The in-memory twin of {@see Rfp} and {@see InboxCampaign}.
 */
final class NamedRecord
{
    public function __construct(
        public string $type,
        public string $id,
        public string $name,
    ) {}
}
