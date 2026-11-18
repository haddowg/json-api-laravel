<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A play — a plain mutable domain object seeded into the in-memory provider. `station` is the
 * id of the station that played it; `track` is the object-graph reference.
 */
final class Play
{
    public function __construct(
        public string $id = '',
        public string $station = '',
        public ?\DateTimeImmutable $played_at = null,
        public ?Track $track = null,
    ) {}
}
