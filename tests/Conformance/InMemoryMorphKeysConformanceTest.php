<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see MorphKeysConformanceTestCase} against the in-memory witness, core's reference
 * handler comparing the fields `identifiedByColumns()` and `identifiedByRows()` name.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryMorphKeysConformanceTest extends MorphKeysConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
