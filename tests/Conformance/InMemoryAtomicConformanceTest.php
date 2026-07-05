<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * {@see AtomicConformanceTestCase} against the **in-memory witness** — the ground-truth half
 * of the dual-provider atomic contract. The cross-store snapshot coordinator makes a batch
 * spanning the artists + albums stores roll back identity-coherently, so the all-or-nothing
 * outcomes match the Eloquent half with no database touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryAtomicConformanceTest extends AtomicConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SurfaceInMemoryServiceProvider::class;
    }
}
