<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ServerScopedAuthorizationTestCase} over the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryServerScopedAuthorizationTest extends ServerScopedAuthorizationTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
