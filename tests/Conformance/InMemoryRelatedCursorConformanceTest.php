<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceInMemoryServiceProvider;

/**
 * {@see RelatedCursorConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the related-collection cursor (keyset) referee. The data lives
 * in the provider registration ({@see RelatedCursorConformanceInMemoryServiceProvider}
 * seeds the group partition from the shared fixtures), so {@see seedConformanceData()}
 * no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelatedCursorConformanceTest extends RelatedCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelatedCursorConformanceInMemoryServiceProvider::class;
    }
}
