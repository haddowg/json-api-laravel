<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughAccess;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ThroughAccessTestCase} over in-memory providers, where scoping providers narrow each hop.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryThroughAccessTest extends ThroughAccessTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
