<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

/**
 * The in-memory `tags` POPO twin of {@see Tag} — the far member of the join to-manys and a
 * polymorphic `feature` member.
 */
final class TagDomain
{
    public function __construct(
        public string $id = '',
        public string $label = '',
    ) {}
}
