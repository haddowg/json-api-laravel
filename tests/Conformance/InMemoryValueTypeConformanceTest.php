<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ValueTypeInMemoryServiceProvider;

/**
 * The JSON type conformance cases over the in-memory provider.
 */
#[CoversNothing]
final class InMemoryValueTypeConformanceTest extends ValueTypeConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ValueTypeInMemoryServiceProvider::class;
    }
}
