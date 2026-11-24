<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsPrefixConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'prefix';
    }

    protected function store(): string
    {
        return 'memory';
    }
}
