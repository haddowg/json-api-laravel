<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ForeignKeyInMemoryServiceProvider;

/**
 * {@see ForeignKeyConformanceTestCase} with the related rows in memory.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryForeignKeyConformanceTest extends ForeignKeyConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ForeignKeyInMemoryServiceProvider::class;
    }
}
