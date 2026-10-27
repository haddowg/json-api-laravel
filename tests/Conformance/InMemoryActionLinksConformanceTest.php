<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks\InMemoryActionLinksServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryActionLinksConformanceTest extends ActionLinksConformanceTestCase
{
    protected function fixtureServiceProvider(): string
    {
        return InMemoryActionLinksServiceProvider::class;
    }
}
