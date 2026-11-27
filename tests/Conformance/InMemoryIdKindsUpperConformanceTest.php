<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsUpperConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'upper';
    }

    protected function store(): string
    {
        return 'memory';
    }
}
