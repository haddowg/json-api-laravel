<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsBinaryConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'binary';
    }

    protected function store(): string
    {
        return 'memory';
    }
}
