<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelationBoundsInMemoryServiceProvider;

/**
 * {@see RelationBoundsConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationBoundsConformanceTest extends RelationBoundsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelationBoundsInMemoryServiceProvider::class;
    }
}
