<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SingletonInMemoryServiceProvider;

/**
 * {@see SingletonConformanceTestCase} on the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemorySingletonConformanceTest extends SingletonConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SingletonInMemoryServiceProvider::class;
    }
}
