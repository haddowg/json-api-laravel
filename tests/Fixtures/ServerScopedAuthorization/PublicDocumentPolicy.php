<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Workbench\App\Models\User;

/**
 * The `default` server's documents: anyone signed in reads them, nobody changes them, and
 * nobody sees their revisions.
 */
final class PublicDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, object $document): bool
    {
        return true;
    }

    public function update(User $user, object $document): bool
    {
        return false;
    }

    public function delete(User $user, object $document): bool
    {
        return false;
    }

    public function viewRevisions(User $user, object $document): bool
    {
        return false;
    }
}
