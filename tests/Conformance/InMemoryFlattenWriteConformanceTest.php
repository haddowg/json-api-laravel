<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see FlattenWriteConformanceTestCase} against the in-memory witness, whose stores share
 * object references, so a flattened write reaches the related store without a save.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryFlattenWriteConformanceTest extends FlattenWriteConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
