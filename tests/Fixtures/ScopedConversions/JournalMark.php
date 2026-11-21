<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

/**
 * A link row a journal's `marks` keep in {@see JournalMarks}, its `tone` untyped so the
 * value a write leaves shows.
 *
 * @internal
 */
final class JournalMark
{
    public function __construct(
        public string $journal,
        public string $type,
        public string $id,
        public mixed $tone = null,
    ) {}
}
