<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceInMemoryServiceProvider;

/**
 * {@see LinkageCursorConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the relationship-linkage cursor (keyset) referee, over the
 * SAME {@see RelatedCursorConformanceInMemoryServiceProvider} wiring the related
 * suite uses. The data lives in the provider registration, so
 * {@see seedConformanceData()} no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryLinkageCursorConformanceTest extends LinkageCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelatedCursorConformanceInMemoryServiceProvider::class;
    }
}
