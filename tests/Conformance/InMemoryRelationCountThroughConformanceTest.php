<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see RelationCountThroughConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationCountThroughConformanceTest extends RelationCountThroughConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
