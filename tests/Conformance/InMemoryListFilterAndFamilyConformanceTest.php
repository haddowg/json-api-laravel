<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ListFilterAndFamilyConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryListFilterAndFamilyConformanceTest extends ListFilterAndFamilyConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
