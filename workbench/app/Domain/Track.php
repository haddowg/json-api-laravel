<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A track — a plain mutable domain object mirroring the Eloquent {@see \Workbench\App\Models\Track}
 * so the shared {@see \Workbench\App\JsonApi\TrackResource} reads off either provider. The
 * far side of the playlist pivot relation on the in-memory witness.
 *
 * @param list<Playlist> $playlists the inverse membership (the object-graph backing for the
 *                                  `belongsToMany('playlists')` the {@see \Workbench\App\JsonApi\TrackResource}
 *                                  declares); wired to the same {@see Playlist} instances the
 *                                  playlists store holds
 */
final class Track
{
    /**
     * @param list<Playlist> $playlists
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?\DateTimeImmutable $released_at = null,
        public array $playlists = [],
    ) {}
}
