<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\LinkRowsInMemoryServiceProvider;

/**
 * {@see OpenPivotLinkRowsConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryOpenPivotLinkRowsConformanceTest extends OpenPivotLinkRowsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return LinkRowsInMemoryServiceProvider::class;
    }
}
