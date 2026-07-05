<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\PivotCursorConformanceInMemoryServiceProvider;

/**
 * {@see PivotCursorConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the pivot-bearing related-collection cursor (keyset) referee.
 * The data lives in the provider registration
 * ({@see PivotCursorConformanceInMemoryServiceProvider} seeds the board partition —
 * membership AND pivot positions — from the shared fixtures), so
 * {@see seedConformanceData()} no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPivotCursorConformanceTest extends PivotCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PivotCursorConformanceInMemoryServiceProvider::class;
    }
}
