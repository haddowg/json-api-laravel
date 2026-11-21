<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughRelations;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ThroughRelationsTestCase} over in-memory providers, where a scoping provider serves the
 * advertisers and narrows every hop into them.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryThroughRelationsTest extends ThroughRelationsTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
