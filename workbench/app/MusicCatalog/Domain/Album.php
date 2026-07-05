<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * An album — a plain mutable domain object seeded into the in-memory provider. Property
 * names are the storage columns the {@see \Workbench\App\MusicCatalog\JsonApi\AlbumResource}
 * fields resolve to; `artist`/`tracks` are the object-graph relation references (the
 * in-memory analogue of the FKs). `release_info` round-trips the `releaseInfo` Map.
 */
final class Album
{
    /**
     * @param array<string, mixed>|null $release_info the `releaseInfo` Map value (label/catalogueNumber)
     * @param ?Artist                   $artist       the album's artist (null → `data: null` linkage)
     * @param list<Track>               $tracks       the album's tracks
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?float $average_rating = null,
        public ?string $artwork = null,
        public ?\DateTimeImmutable $released_at = null,
        public bool $explicit = false,
        public string $status = 'released',
        public ?\DateTimeImmutable $available_from = null,
        public ?\DateTimeImmutable $available_until = null,
        public ?array $release_info = null,
        public ?Artist $artist = null,
        public array $tracks = [],
    ) {}
}
