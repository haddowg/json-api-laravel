<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * An ordered playlist membership — the in-memory pivot row backing `playlists.orderedTracks`
 * (the analogue of the Symfony example's `PlaylistEntry` association entity). Carries the
 * `position`/`weight`/`added_at` pivot columns beside the far {@see Track}.
 */
final class PlaylistEntry
{
    public function __construct(
        public ?Track $track = null,
        public int $position = 1,
        public ?int $weight = null,
        public ?\DateTimeImmutable $added_at = null,
    ) {}
}
