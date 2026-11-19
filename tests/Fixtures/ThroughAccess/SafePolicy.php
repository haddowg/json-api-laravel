<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Workbench\App\Models\User;

/**
 * Anyone may list safes, but only admins may read one, whichever it is.
 */
final class SafePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return $user->is_admin;
    }
}
