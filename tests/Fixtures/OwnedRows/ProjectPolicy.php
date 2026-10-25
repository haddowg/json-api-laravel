<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use Workbench\App\Models\User;

final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, object $project): bool
    {
        return true;
    }

    /**
     * Who may see a project's tasks at all. Which tasks they see is the task scope's job.
     */
    public function viewTasks(User $user, object $project): bool
    {
        return $user->can_read;
    }
}
