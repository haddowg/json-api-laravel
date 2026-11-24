<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate;

use Workbench\App\Models\User;

/**
 * Only admins may list spokes.
 */
final class SpokePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, object $spoke): bool
    {
        return true;
    }
}
