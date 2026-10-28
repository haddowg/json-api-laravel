<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ServerHeaderDefaultsTestCase} over the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryServerHeaderDefaultsTest extends ServerHeaderDefaultsTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
