<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * A richer domain POPO for the in-memory data-provider criteria unit tests: it carries a
 * string column (`title`, `status`), a nullable numeric column (`rating`), a boolean
 * (`explicit`), a nullable date-time (`releasedAt`) and a to-many relation (`tags`), so
 * every core filter type — comparison, convenience, id-set, null, range/date-range,
 * relationship-existence and traversal — has a column to bind against. Core's `Accessor`
 * reads the public properties exactly as it would a serialized attribute.
 *
 * @internal
 */
final class Song
{
    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?float $rating,
        public bool $explicit,
        public ?\DateTimeImmutable $releasedAt,
        public array $tags = [],
    ) {}
}
