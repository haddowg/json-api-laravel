<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use Workbench\App\Models\User;

final class TaskPolicy
{
    /**
     * Only an admin may list tasks directly through `GET /tasks`.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }
}
