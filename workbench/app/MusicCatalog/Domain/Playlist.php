<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A playlist — a plain mutable domain object with an app-minted UUID id. `owner` backs both
 * the admin `owner` relation and the curated `publicOwner` view (same column). `tracks` is
 * the plain join; `entries` carries the ordered pivot rows for `orderedTracks`.
 */
final class Playlist
{
    /**
     * @param ?User            $owner   the playlist's owner
     * @param list<Track>      $tracks  the plain (bare-join) tracks
     * @param list<PlaylistEntry> $entries the ordered pivot rows backing `orderedTracks`
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $slug = '',
        public bool $public = true,
        public ?string $external_id = null,
        public ?User $owner = null,
        public array $tracks = [],
        public array $entries = [],
    ) {}
}
