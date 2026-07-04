<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * {@see WriteConformanceTestCase} against the **in-memory witness** — the ground-truth
 * half of the dual-provider write contract. The {@see WorkbenchServiceProvider} registers
 * the writable `albums`/`genres` providers with a shared-store
 * {@see \haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister} seeded from the
 * minimal {@see \Workbench\App\Support\Fixtures}, so a write is immediately readable and
 * no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryWriteConformanceTest extends WriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return WorkbenchServiceProvider::class;
    }
}
