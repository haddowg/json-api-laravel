<?php

declare(strict_types=1);

namespace Workbench\App\SoftDelete\Policies;

use Workbench\App\Models\Document;
use Workbench\App\Models\User;

/**
 * The model-registered policy for {@see Document} (mapped via
 * `Gate::policy(Document::class, self::class)` in {@see \Workbench\App\Providers\SoftDeleteEloquentServiceProvider}).
 * It demonstrates that the synthesized soft-delete actions dispatch to Laravel's **native**
 * soft-delete policy convention with no package-specific registration: the `restore` action's
 * `restore` ability resolves to {@see restore()} and the `force-delete` action's `forceDelete`
 * ability to {@see forceDelete()} — the exact methods `php artisan make:policy` scaffolds for a
 * soft-deletable model.
 *
 * The CRUD abilities are open (every authenticated user); the two soft-delete abilities are
 * deliberately distinct — `restore` needs write, `forceDelete` needs admin — so the suite can
 * witness that restore/force-delete are gated separately from an ordinary update/delete.
 */
final class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Document $document): bool
    {
        return true;
    }

    public function delete(User $user, Document $document): bool
    {
        return true;
    }

    /**
     * Restoring a trashed document requires a write-capable user (distinct from the open
     * `delete` ability — the point of first-class soft deletes' separate authorization).
     */
    public function restore(User $user, Document $document): bool
    {
        return $user->can_write;
    }

    /**
     * Permanently destroying a document requires an admin — the most privileged, least
     * reversible action.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return $user->is_admin;
    }
}
