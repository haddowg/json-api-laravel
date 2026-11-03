<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SortFamilyConformanceInMemoryServiceProvider;

/**
 * {@see SortFamilyConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemorySortFamilyConformanceTest extends SortFamilyConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SortFamilyConformanceInMemoryServiceProvider::class;
    }
}
