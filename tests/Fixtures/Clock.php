<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * A trivial container-resolvable service used to prove that a JSON:API resource with a
 * real constructor dependency is built through the application container (core's lazy
 * resolver → `app()->make()`), not plain-`new`ed. It is an INTERFACE on purpose: an
 * interface-typed required constructor parameter cannot be satisfied by a plain
 * `new ClockStampResource()`, so a passing end-to-end test can only mean the container
 * resolved the binding.
 *
 * @internal
 */
interface Clock
{
    public function label(): string;
}
