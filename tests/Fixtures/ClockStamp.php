<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * The backing domain object for the `clock-stamps` fixture type — a plain POPO seeded
 * into the in-memory provider. The `stamp` attribute is computed off the resource's
 * injected {@see Clock}, not read from this object, so it only needs to carry an id.
 *
 * @internal
 */
final class ClockStamp
{
    public function __construct(public string $id) {}
}
