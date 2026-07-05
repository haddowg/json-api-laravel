<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList;

/**
 * A trivial domain POPO for the Atomic Operations allow-list fixtures.
 */
final class Entry
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {}
}
