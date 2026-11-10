<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\LoadedLinkage;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryLoadedLinkageTest extends LoadedLinkageTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
