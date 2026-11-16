<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see InboxFilterConformanceTestCase} against the in-memory witness, core's reference
 * handler comparing the fields `identifiedByColumns()` and `identifiedByRows()` name.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryInboxFilterConformanceTest extends InboxFilterConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
