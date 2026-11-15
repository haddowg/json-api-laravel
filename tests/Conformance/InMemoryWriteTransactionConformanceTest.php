<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\TxInMemoryServiceProvider;

/**
 * {@see WriteTransactionConformanceTestCase} on the in-memory witness, whose transaction is
 * a snapshot of both stores taken through one coordinator.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryWriteTransactionConformanceTest extends WriteTransactionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TxInMemoryServiceProvider::class;
    }
}
