<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * {@see RawResponseConformanceTestCase} over the in-memory provider and persister.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRawResponseConformanceTest extends RawResponseConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return WorkbenchServiceProvider::class;
    }
}
