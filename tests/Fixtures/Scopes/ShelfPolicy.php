<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use Workbench\App\Models\User;

final class ShelfPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->is_admin === true ? true : null;
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, object $shelf): bool
    {
        return true;
    }
}
