<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OwnedRows;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see OwnedRowsTestCase} with a scoping provider that owns the `tasks` type.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryOwnedRowsTest extends OwnedRowsTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
