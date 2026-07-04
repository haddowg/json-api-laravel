<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\CursorConformanceInMemoryServiceProvider;

/**
 * {@see CursorConformanceTestCase} against the **in-memory witness** — the ground-truth
 * half of the cursor (keyset) referee. The data lives in the provider registration
 * ({@see CursorConformanceInMemoryServiceProvider} seeds it from the shared fixtures),
 * so {@see seedConformanceData()} no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryCursorConformanceTest extends CursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CursorConformanceInMemoryServiceProvider::class;
    }
}
