<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A recording artist — a plain mutable domain object (no base class, no Eloquent),
 * mirroring core's in-memory music-catalog example. The
 * {@see \Workbench\App\JsonApi\ArtistResource} field column names match these property
 * names exactly, so the default field reader (`Accessor::get()`) resolves them off the
 * public properties.
 *
 * Eloquent models arrive in Phase 1 (where a real query layer is needed); Phase 0 seeds
 * these POPOs into the in-memory provider, the lowest-risk seed for a reads-only slice.
 */
final class Artist
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $slug = '',
        public ?string $website = null,
        public ?string $bio = null,
        public int $trackCount = 0,
        public ?\DateTimeImmutable $createdAt = null,
    ) {}
}
