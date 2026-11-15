<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see MissingLinkageConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryMissingLinkageConformanceTest extends MissingLinkageConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }
}
