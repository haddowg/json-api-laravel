<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Workbench\App\Models\User;

/**
 * Anyone may list and read the type.
 */
final class OpenPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, object $row): bool
    {
        return true;
    }
}
