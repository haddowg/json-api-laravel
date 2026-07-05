<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdPattern;

/**
 * A trivial domain POPO for the Id-route-pattern fixture.
 */
final class NumericThing
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {}
}
