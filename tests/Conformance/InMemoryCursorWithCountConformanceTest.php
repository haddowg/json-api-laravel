<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\CursorWithCountConformanceInMemoryServiceProvider;

/**
 * {@see CursorWithCountConformanceTestCase} against the in-memory provider, whose fixtures
 * live in the provider registration.
 */
#[CoversNothing]
final class InMemoryCursorWithCountConformanceTest extends CursorWithCountConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CursorWithCountConformanceInMemoryServiceProvider::class;
    }
}
