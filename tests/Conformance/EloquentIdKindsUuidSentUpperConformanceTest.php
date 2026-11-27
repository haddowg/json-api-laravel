<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EloquentIdKindsUuidSentUpperConformanceTest extends EloquentIdKindsConformanceTestCase
{
    protected function kind(): string
    {
        return 'uuid';
    }

    protected function sentCase(): string
    {
        return 'upper';
    }
}
