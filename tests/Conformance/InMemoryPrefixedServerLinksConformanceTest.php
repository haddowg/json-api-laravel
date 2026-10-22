<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * {@see PrefixedServerLinksConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPrefixedServerLinksConformanceTest extends PrefixedServerLinksConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return WorkbenchServiceProvider::class;
    }
}
