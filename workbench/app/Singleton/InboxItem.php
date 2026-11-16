<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

/**
 * The in-memory twin of {@see \Workbench\App\Models\InboxItem}.
 */
final class InboxItem
{
    public function __construct(
        public string $id = '',
        public string $subject = '',
        public ?Inbox $inbox = null,
    ) {}
}
