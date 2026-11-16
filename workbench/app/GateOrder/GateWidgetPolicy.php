<?php

declare(strict_types=1);

namespace Workbench\App\GateOrder;

use haddowg\JsonApi\Resource\Field\Accessor;
use Workbench\App\Models\User;

/**
 * An admin may do anything. A user may list widgets when they can read, read any widget but
 * "secret", and update the widgets they own. Only an admin may create, delete, poke, change
 * `lockedPartner` or read `adminPartner`.
 */
final class GateWidgetPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->is_admin === true ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can_read;
    }

    public function view(User $user, object $widget): bool
    {
        return Accessor::get($widget, 'name') !== 'secret';
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, object $widget): bool
    {
        return Accessor::get($widget, 'owner') === $user->name;
    }

    public function delete(User $user, object $widget): bool
    {
        return false;
    }

    public function changeLockedPartner(User $user, object $widget): bool
    {
        return false;
    }

    public function readAdminPartner(User $user, object $widget): bool
    {
        return false;
    }

    public function poke(User $user, object $widget): bool
    {
        return false;
    }
}
