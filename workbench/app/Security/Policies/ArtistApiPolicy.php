<?php

declare(strict_types=1);

namespace Workbench\App\Security\Policies;

use Workbench\App\Models\Artist;
use Workbench\App\Models\User;

/**
 * The **model-registered** policy (PLAN decision 7, the default resolution path): it is
 * NOT named on the resource attribute. Instead the {@see \Workbench\App\Providers\GatePolicyServiceProvider}
 * maps it to the Eloquent {@see Artist} model with `Gate::policy(Artist::class, self::class)`,
 * so the {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} resolves it automatically
 * through the Gate — the idiomatic Laravel authorization the package inherits for free.
 *
 * Its class basename is deliberately `ArtistApiPolicy` (not `ArtistPolicy`) so Laravel's
 * convention-based policy auto-discovery never binds it to the *music-suite* Artist model
 * — that suite stays inert.
 *
 * It has no `browseArtists` method on purpose: the resource renames the list ability to
 * `browseArtists`, which this policy lacks, so the Gate falls through to the
 * `Gate::define('browseArtists', …)` closure — the **Gate::define** resolution path.
 */
final class ArtistApiPolicy
{
    /**
     * Only a write-capable user may inspect a single artist record (a synthetic
     * staff-only rule, so the read `view` ability has a demonstrable denial through the
     * model-registered policy path).
     */
    public function view(User $user, Artist $artist): bool
    {
        return $user->can_write;
    }

    /**
     * Only a write-capable user may create an artist.
     */
    public function create(User $user): bool
    {
        return $user->can_write;
    }

    /**
     * Only a write-capable user may update an artist.
     */
    public function update(User $user, Artist $artist): bool
    {
        return $user->can_write;
    }

    /**
     * Only an admin may delete an artist.
     */
    public function delete(User $user, Artist $artist): bool
    {
        return $user->is_admin;
    }
}
