<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see FixedMembershipConformanceTestCase} against the **in-memory witness**: the current
 * members are the plain list on the stored playlist's `tracks` property.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryFixedMembershipConformanceTest extends FixedMembershipConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }

}
