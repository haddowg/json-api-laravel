<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Workbench\App\Models\User;

/**
 * Anyone may read crates; only admins may read their `ledger`, and the `auditor` permission
 * reads the crate, so it can't answer for every crate at once.
 */
final class CratePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, object $crate): bool
    {
        return true;
    }

    public function readLedger(User $user): bool
    {
        return $user->is_admin;
    }

    public function auditCrate(User $user, object $crate): bool
    {
        return true;
    }
}
