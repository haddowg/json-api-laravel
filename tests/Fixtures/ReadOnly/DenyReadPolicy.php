<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly;

use Workbench\App\Models\User;

/**
 * A deny-all dedicated API policy: every read ability is refused. Declared on the
 * read-only {@see ChartResource} to prove a DECLARED `policy:` is enforced on a
 * persister-less type — the collection endpoint mints no list instance, yet the policy's
 * class-level `viewAny` still runs (against the resource-class token) and denies, so the
 * list is NOT fail-open.
 */
final class DenyReadPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, object $subject): bool
    {
        return false;
    }
}
