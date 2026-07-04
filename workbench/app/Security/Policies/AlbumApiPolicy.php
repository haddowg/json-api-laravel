<?php

declare(strict_types=1);

namespace Workbench\App\Security\Policies;

use Workbench\App\Models\User;

/**
 * The **dedicated API policy** (PLAN decision 7 showcase) invoked directly by the
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} because the secured
 * {@see \Workbench\App\Security\AlbumResource} declares `policy: self::class`. It is
 * provider-agnostic: its subject is typed `object`, so the SAME policy authorizes the
 * in-memory {@see \Workbench\App\Domain\Album} POPO and the Eloquent
 * {@see \Workbench\App\Models\Album} model — the natural fit for the dual-provider
 * authorization conformance suite.
 *
 * It demonstrates the two decision-7 showcase requirements:
 *  - the {@see before()} bypass — an admin is authorized for every ability regardless
 *    of the per-ability rule (proving `before()` is honoured);
 *  - the **API-distinct ability rename** — the resource maps `create` to `publish`, so
 *    the Gate calls {@see publish()} rather than a conventional `create()`.
 *
 * It intentionally has no `delete` method: the resource disables the delete check
 * (`Delete => false`), so delete is never authorized here.
 */
final class AlbumApiPolicy
{
    /**
     * An admin bypasses every check — the honoured `before()` gate. A non-admin (or a
     * guest) falls through to the per-ability method by returning `null`. Nullable user
     * so the Gate lets a guest reach `before()` (which then declines to bypass).
     */
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->is_admin === true ? true : null;
    }

    /**
     * A read-capable user may list albums — so the dual-provider suite can assert a
     * DENIED list (a `403` before the query) on the dedicated-policy read path, not only
     * an allowed one. A non-admin without read access is denied here (an admin still
     * bypasses via before()).
     */
    public function viewAny(User $user): bool
    {
        return $user->can_read;
    }

    /**
     * A read-capable user may read a single album — the denied-read counterpart (a `403`
     * after the model loads, on an existing id) on the dedicated-policy path.
     */
    public function view(User $user, object $album): bool
    {
        return $user->can_read;
    }

    /**
     * The API-distinct ability: `create` is renamed to `publish` on the resource, so the
     * Gate calls THIS method. Only a write-capable user may publish a new album.
     */
    public function publish(User $user): bool
    {
        return $user->can_write;
    }

    /**
     * Only a write-capable user may update an album.
     */
    public function update(User $user, object $album): bool
    {
        return $user->can_write;
    }
}
