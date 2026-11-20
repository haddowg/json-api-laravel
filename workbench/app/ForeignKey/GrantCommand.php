<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

/**
 * The input {@see ReviseGrantByCommandAction} builds itself: the ids of the locked secrets an
 * `fk-revisable-grants` document names.
 */
final class GrantCommand
{
    /**
     * @var list<string>
     */
    public array $lockedSecrets = [];
}
