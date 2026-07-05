<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A read-only chart — the Laravel twin of the bundle example's `Chart`. It has **no
 * Eloquent model and no Resource**: it is served by a standalone hand-written
 * {@see \Workbench\App\MusicCatalog\Serializer\ChartSerializer} (registered by
 * `#[\haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer]`) plus a small custom
 * {@see \Workbench\App\MusicCatalog\Provider\ChartProvider} that returns a fixed list —
 * the capability-composition witness for a resource-less, read-only, serialize-plus-fetch
 * type (PLAN decision 3, bundle ADR 0024).
 */
final class Chart
{
    /**
     * @param list<array{rank: int, trackId: string, plays: int}> $entries
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $period = '',
        public array $entries = [],
    ) {}
}
