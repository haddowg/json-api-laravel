<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * An album — a plain mutable domain object seeded into the in-memory provider for the
 * Phase 0 reads-only slice. Property names match the {@see \Workbench\App\JsonApi\AlbumResource}
 * field columns.
 */
final class Album
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?float $averageRating = null,
        public string $status = '',
        public bool $explicit = false,
        public ?\DateTimeImmutable $availableFrom = null,
        public ?\DateTimeImmutable $releasedAt = null,
    ) {}
}
