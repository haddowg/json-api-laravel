<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InMemoryBatch;

/**
 * A parent with a to-many `children` read by the in-memory witness's batched relation fetch.
 */
final class BatchParent
{
    /**
     * @param list<BatchChild> $children
     */
    public function __construct(
        public string $id = '',
        public array $children = [],
    ) {}
}
