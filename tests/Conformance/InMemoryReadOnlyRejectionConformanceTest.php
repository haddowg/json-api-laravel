<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * {@see ReadOnlyRejectionConformanceTestCase} over the in-memory provider and persister.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryReadOnlyRejectionConformanceTest extends ReadOnlyRejectionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return WorkbenchServiceProvider::class;
    }
}
