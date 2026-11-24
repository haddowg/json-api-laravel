<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsUlidConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'ulid';
    }

    protected function store(): string
    {
        return 'memory';
    }
}
