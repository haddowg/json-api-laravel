<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EloquentIdKindsPrefixConformanceTest extends EloquentIdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'prefix';
    }
}
