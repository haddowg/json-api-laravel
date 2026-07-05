<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\CompositeInMemoryServiceProvider;

/**
 * {@see CompositeConformanceTestCase} against the in-memory provider: the composite
 * values live as plain arrays in the shared store.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryCompositeConformanceTest extends CompositeConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CompositeInMemoryServiceProvider::class;
    }
}
