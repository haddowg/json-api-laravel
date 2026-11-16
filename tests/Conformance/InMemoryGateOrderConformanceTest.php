<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\GateOrderInMemoryServiceProvider;

/**
 * {@see GateOrderConformanceTestCase} on the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryGateOrderConformanceTest extends GateOrderConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return GateOrderInMemoryServiceProvider::class;
    }
}
