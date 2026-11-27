<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryIdKindsUuidSentUpperConformanceTest extends IdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'uuid';
    }

    protected function store(): string
    {
        return 'memory';
    }

    protected function sentCase(): string
    {
        return 'upper';
    }
}
