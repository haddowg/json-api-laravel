<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ThroughFilterConformanceTestCase} against the in-memory witness, core's reference
 * traversal.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryThroughFilterConformanceTest extends ThroughFilterConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
