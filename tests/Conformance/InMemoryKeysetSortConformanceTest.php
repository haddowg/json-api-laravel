<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\KeysetSortConformanceInMemoryServiceProvider;

/**
 * {@see KeysetSortConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryKeysetSortConformanceTest extends KeysetSortConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return KeysetSortConformanceInMemoryServiceProvider::class;
    }
}
