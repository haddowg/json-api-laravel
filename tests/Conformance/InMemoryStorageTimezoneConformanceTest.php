<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\StorageTimezoneInMemoryServiceProvider;

#[CoversNothing]
final class InMemoryStorageTimezoneConformanceTest extends StorageTimezoneConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return StorageTimezoneInMemoryServiceProvider::class;
    }
}
