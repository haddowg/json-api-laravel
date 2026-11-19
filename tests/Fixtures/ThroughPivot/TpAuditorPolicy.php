<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Workbench\App\Models\User;

/**
 * Only admins may list auditors.
 */
final class TpAuditorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, object $auditor): bool
    {
        return true;
    }
}
