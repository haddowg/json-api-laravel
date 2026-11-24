<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EloquentIdKindsStringConformanceTest extends EloquentIdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'string';
    }
}
