<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * An artist — a plain mutable domain object seeded into the in-memory provider. Property
 * names are the storage columns the {@see \Workbench\App\MusicCatalog\JsonApi\ArtistResource}
 * fields resolve to (snake_case), shared with the Eloquent
 * {@see \Workbench\App\MusicCatalog\Models\Artist} model so one resource declaration serves
 * both providers.
 */
final class Artist
{
    /**
     * @param list<Album> $albums the artist's albums — the object-graph backing for the
     *                            `HasMany('albums')` relation (the in-memory analogue of the FK)
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $slug = '',
        public ?string $website = null,
        public ?string $bio = null,
        public int $track_count = 0,
        public ?\DateTimeImmutable $created_at = null,
        public array $albums = [],
    ) {}
}
