<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

/**
 * The in-memory twin of {@see Keeper}.
 */
final class KeeperRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
