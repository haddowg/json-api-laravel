<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\OpenObjectInMemoryServiceProvider;

/**
 * The open-object conformance cases over the in-memory provider.
 */
#[CoversNothing]
final class InMemoryOpenObjectConformanceTest extends OpenObjectConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return OpenObjectInMemoryServiceProvider::class;
    }
}
