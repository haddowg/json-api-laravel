<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InMemoryBatch;

/**
 * A child of {@see BatchParent}'s to-many, carrying a shared sort key (`rank`) and a
 * distinct `id`, so the in-memory witness's windowed-batch PK tiebreak can be refereed.
 */
final class BatchChild
{
    public function __construct(
        public string $id = '',
        public int $rank = 0,
    ) {}
}
