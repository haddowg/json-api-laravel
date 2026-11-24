<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A task on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Task}.
 * Nothing in the store refuses a task without an owner, so only validation can.
 */
final class Task
{
    /**
     * @param list<Member> $watchers
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Member $owner = null,
        public array $watchers = [],
    ) {}
}
