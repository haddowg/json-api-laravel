<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RequiredRelationInMemoryServiceProvider;

/**
 * {@see RequiredRelationConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRequiredRelationConformanceTest extends RequiredRelationConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RequiredRelationInMemoryServiceProvider::class;
    }
}
