<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\LinkRowsInMemoryServiceProvider;

/**
 * {@see LinkRowsConformanceTestCase} against the in-memory witness, which queries the link
 * rows the `identifiedBy()` closures return.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryLinkRowsConformanceTest extends LinkRowsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return LinkRowsInMemoryServiceProvider::class;
    }
}
