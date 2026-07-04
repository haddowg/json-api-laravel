<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * An album — a plain mutable domain object seeded into the in-memory provider.
 * Property names are the storage **columns** the {@see \Workbench\App\JsonApi\AlbumResource}
 * fields resolve to (snake_case), shared with the Eloquent {@see \Workbench\App\Models\Album}
 * model so one resource declaration serves both providers (blueprint §3.4/§5).
 */
final class Album
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?float $average_rating = null,
        public string $status = '',
        public bool $explicit = false,
        public ?\DateTimeImmutable $available_from = null,
        public ?\DateTimeImmutable $released_at = null,
    ) {}
}
