<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsUuidConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'uuid';
    }

    protected function store(): string
    {
        return 'memory';
    }
}
