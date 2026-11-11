<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FilterDefaultsOptOut;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryFilterDefaultsOptOutTest extends FilterDefaultsOptOutTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
