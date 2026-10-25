<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Workbench\App\Models\User;

/**
 * The `admin` server's documents: anyone signed in reads them and their revisions, and only
 * an admin edits them.
 */
final class AdminDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, object $document): bool
    {
        return true;
    }

    public function edit(User $user, object $document): bool
    {
        return $user->is_admin;
    }

    public function viewRevisions(User $user, object $document): bool
    {
        return true;
    }
}
