<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle;

/**
 * A minimal mutable domain object for the Phase-4 events / hooks / headers fixtures,
 * seeded into an in-memory provider. Its public property names are the storage columns
 * the fixture resources' fields resolve to.
 */
final class Gizmo
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $status = '',
    ) {}
}
