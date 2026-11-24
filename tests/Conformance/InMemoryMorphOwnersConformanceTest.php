<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryMorphOwnersConformanceTest extends MorphOwnersConformanceTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
