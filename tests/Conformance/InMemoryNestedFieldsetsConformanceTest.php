<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\NestedFieldsetsInMemoryServiceProvider;

/**
 * The nested fieldsets conformance cases over the in-memory provider.
 */
#[CoversNothing]
final class InMemoryNestedFieldsetsConformanceTest extends NestedFieldsetsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return NestedFieldsetsInMemoryServiceProvider::class;
    }
}
