<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A recording artist — a plain mutable domain object (no base class, no Eloquent),
 * mirroring core's in-memory music-catalog example and seeded into the in-memory
 * provider.
 *
 * Property names are the storage **columns** the {@see \Workbench\App\JsonApi\ArtistResource}
 * fields resolve to (snake_case, matching each field's `storedAs()` / `column`), so the
 * SAME resource declaration reads correctly off this POPO (via `Accessor` property
 * access) and off the Eloquent {@see \Workbench\App\Models\Artist} model (via cast
 * attributes) — the shared-resource, dual-provider seam (blueprint §3.4/§5).
 */
final class Artist
{
    /**
     * @param list<Album> $albums the artist's albums — the object-graph backing for the
     *                            `HasMany('albums')` relation the {@see \Workbench\App\JsonApi\ArtistResource}
     *                            declares; the in-memory service provider wires it to the
     *                            same {@see Album} instances the albums store holds, so a
     *                            related/include read resolves off this property exactly as
     *                            the Eloquent `albums()` HasMany does off the model
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
