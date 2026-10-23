<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedIncludes;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class EloquentGlobalScopeDefaultServerTest extends ScopedIncludesTestCase
{
    protected function server(): string
    {
        return 'default';
    }

    protected function store(): string
    {
        return 'eloquent';
    }

    protected function scope(): string
    {
        return 'global';
    }
}
