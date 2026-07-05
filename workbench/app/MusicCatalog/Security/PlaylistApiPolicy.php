<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Security;

use haddowg\JsonApi\Resource\Field\Accessor;
use Workbench\App\Models\User;

/**
 * The dedicated API policy for `playlists` (PLAN decision 7 showcase) — the Laravel
 * replacement for the Symfony example's `PlaylistOwnerVoter` + `securityUpdate`/
 * `securityDelete` expressions. Invoked directly (the resource declares
 * `policy: self::class`), provider-agnostic (subject typed `object`, so it authorizes both
 * the Eloquent model and the in-memory POPO).
 *
 * It demonstrates the decision-7 requirements:
 *  - the {@see before()} admin bypass (honoured);
 *  - the **API-distinct ability renames** — `update` → {@see curate()} (an owner gate) and
 *    `delete` → {@see deletePlaylist()} (an admin-only gate);
 *  - a per-relation ability — {@see inspectOwner()}, gating the `owner` relation's read
 *    endpoints (`security(read: 'inspectOwner')`).
 */
final class PlaylistApiPolicy
{
    /**
     * An admin bypasses every check; a non-admin falls through by returning null.
     */
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->is_admin === true ? true : null;
    }

    /**
     * A playlist is publicly listable.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * A playlist is publicly readable.
     */
    public function view(?User $user, object $playlist): bool
    {
        return true;
    }

    /**
     * The API-distinct update ability: only the playlist's owner may curate it (an admin
     * bypasses via {@see before()}).
     */
    public function curate(User $user, object $playlist): bool
    {
        $ownerId = Accessor::get($playlist, 'owner_id');
        if ($ownerId === null) {
            $owner = Accessor::get($playlist, 'owner');
            $ownerId = \is_object($owner) ? Accessor::get($owner, 'id') : null;
        }

        $identifier = $user->getAuthIdentifier();

        return \is_scalar($ownerId) && \is_scalar($identifier) && (string) $ownerId === (string) $identifier;
    }

    /**
     * The API-distinct delete ability: admin-only (only {@see before()} grants it).
     */
    public function deletePlaylist(User $user, object $playlist): bool
    {
        return false;
    }

    /**
     * The per-relation read ability for `owner`: admin-only (only {@see before()} grants it),
     * so a non-admin reading `…/playlists/{id}/owner` is a `403` while `publicOwner` stays
     * open.
     */
    public function inspectOwner(User $user, object $playlist): bool
    {
        return false;
    }
}
