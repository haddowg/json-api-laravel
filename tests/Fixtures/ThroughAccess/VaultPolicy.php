<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Workbench\App\Models\User;

/**
 * Only admins may list vaults.
 */
final class VaultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, object $vault): bool
    {
        return true;
    }
}
