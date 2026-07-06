<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\AsyncInMemoryServiceProvider;

/**
 * {@see AsyncWriteConformanceTestCase} against the **in-memory witness** — the ground-truth
 * half of the async-write contract. The {@see AsyncInMemoryServiceProvider} routes `albums`
 * writes to the async persister over the seeded in-memory providers, so the deferred write
 * is never committed and the seeded rows are unchanged after a `202`.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryAsyncWriteConformanceTest extends AsyncWriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return AsyncInMemoryServiceProvider::class;
    }
}
