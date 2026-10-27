<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * {@see MethodNotAllowedConformanceTestCase} over the in-memory provider and persister.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryMethodNotAllowedConformanceTest extends MethodNotAllowedConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return WorkbenchServiceProvider::class;
    }
}
