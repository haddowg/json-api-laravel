<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\TimezoneInMemoryServiceProvider;

/**
 * {@see OutputTimezoneConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryOutputTimezoneConformanceTest extends OutputTimezoneConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TimezoneInMemoryServiceProvider::class;
    }
}
