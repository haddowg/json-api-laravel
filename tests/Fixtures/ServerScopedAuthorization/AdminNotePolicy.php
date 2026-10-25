<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Workbench\App\Models\User;

final class AdminNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, object $note): bool
    {
        return $user->is_admin;
    }
}
