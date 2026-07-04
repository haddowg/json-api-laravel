<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see ReadConformanceTestCase} against the **in-memory witness** — the ground-truth
 * half of the dual-provider contract. The data lives in the provider registration
 * ({@see ConformanceInMemoryServiceProvider} seeds it from the shared
 * {@see \Workbench\App\Support\ConformanceFixtures}), so {@see seed()} no-ops and no
 * database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryReadConformanceTest extends ReadConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }
}
