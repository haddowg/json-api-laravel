<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

/**
 * A blog author — a leaf POPO type, a member of the polymorphic `feature` / `related`
 * relations a {@see Post} declares (the in-memory witness's polymorphic to-one AND
 * to-many exercise).
 */
final class Author
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
