<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate;

use Workbench\App\Models\User;

/**
 * Only its owner may read an owned hub.
 */
final class OwnedHubPolicy
{
    public function view(User $user, object $hub): bool
    {
        return $hub instanceof Hub && $hub->owner === $user->name;
    }
}
