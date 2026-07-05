<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A track — a plain mutable domain object seeded into the in-memory provider. Property
 * names are the storage columns the {@see \Workbench\App\MusicCatalog\JsonApi\TrackResource}
 * fields resolve to (`length_seconds` backs the `durationSeconds` attribute via
 * `storedAs()`); `album`/`playlists` are the object-graph relation references.
 */
final class Track
{
    /**
     * @param list<string>   $genres    the track's genres (an ArrayList attribute)
     * @param ?Album         $album     the track's album
     * @param list<Playlist> $playlists the playlists this track appears on (the plain join)
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public int $track_number = 1,
        public int $length_seconds = 0,
        public bool $explicit = false,
        public array $genres = [],
        public ?string $preview_offset = null,
        public ?Album $album = null,
        public array $playlists = [],
    ) {}
}
