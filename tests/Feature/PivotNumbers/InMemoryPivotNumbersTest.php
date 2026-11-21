<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotNumbers;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers\PivotNumbersInMemoryServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\LinkRowsInMemoryServiceProvider;

/**
 * {@see PivotNumbersTestCase} on the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPivotNumbersTest extends PivotNumbersTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, LinkRowsInMemoryServiceProvider::class, PivotNumbersInMemoryServiceProvider::class];
    }
}
