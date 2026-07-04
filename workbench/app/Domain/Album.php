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
    /**
     * @param ?Artist $artist the album's artist — the object-graph backing for the
     *                        `BelongsTo('artist')` relation the {@see \Workbench\App\JsonApi\AlbumResource}
     *                        declares (null for an unowned album → `data: null` linkage);
     *                        the in-memory service provider wires it to the same {@see Artist}
     *                        instance the artists store holds, resolving exactly as the
     *                        Eloquent `artist()` BelongsTo does off the model
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?float $average_rating = null,
        public string $status = '',
        public bool $explicit = false,
        public ?\DateTimeImmutable $available_from = null,
        public ?\DateTimeImmutable $released_at = null,
        public ?Artist $artist = null,
    ) {}
}
