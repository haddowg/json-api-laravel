<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use Workbench\App\Models\User;

final class GadgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can_read;
    }
}
