<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * A plain domain object for the data-layer unit tests — the in-memory store holds POPOs
 * exactly like these, and core's `Accessor` reads their public properties.
 *
 * @internal
 */
final class Widget
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
