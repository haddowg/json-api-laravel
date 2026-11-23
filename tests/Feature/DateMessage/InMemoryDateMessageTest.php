<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\DateMessage;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see DateMessageTestCase} over the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryDateMessageTest extends DateMessageTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
