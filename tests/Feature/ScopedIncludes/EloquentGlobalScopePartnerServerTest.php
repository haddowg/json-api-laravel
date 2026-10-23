<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedIncludes;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class EloquentGlobalScopePartnerServerTest extends ScopedIncludesTestCase
{
    protected function server(): string
    {
        return 'partner';
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
