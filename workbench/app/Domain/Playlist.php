<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A playlist — a plain mutable domain object mirroring the Eloquent {@see \Workbench\App\Models\Playlist}
 * so the shared {@see \Workbench\App\JsonApi\PlaylistResource} reads off either provider. The
 * parent of the pivot + relationship-mutation surface on the in-memory witness.
 *
 * The in-memory store holds NO pivot data (a pivot column needs an association entity the
 * in-memory provider cannot model — the documented pivot boundary), so `meta.pivot` renders
 * Eloquent-only. Both `$orderedTracks` and `$tracks` hold the plain member list the
 * `belongsToMany` relations resolve off; a relationship mutation replaces the list without
 * storing any pivot meta.
 */
final class Playlist
{
    /**
     * @param list<Track> $orderedTracks the pivot-relation members (no pivot meta stored in-memory)
     * @param list<Track> $tracks        the bare-join members + the relationship-existence filter target
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public bool $public = true,
        public array $orderedTracks = [],
        public array $tracks = [],
    ) {}
}
