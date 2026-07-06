<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SparseInMemoryServiceProvider;

/**
 * {@see SparseByDefaultConformanceTestCase} against the in-memory provider: the single
 * `sparseWidgets` row lives in the provider registration, so {@see seedConformanceData()}
 * no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemorySparseByDefaultConformanceTest extends SparseByDefaultConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SparseInMemoryServiceProvider::class;
    }
}
