<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate;

use Workbench\App\Models\User;

/**
 * Any user may read a hub and its shared spokes.
 */
final class HubPolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function readShared(User $user): bool
    {
        return true;
    }
}
