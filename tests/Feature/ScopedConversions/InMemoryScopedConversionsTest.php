<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedConversions;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions\ScopedConversionsInMemoryServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ScopedConversionsTestCase} on the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryScopedConversionsTest extends ScopedConversionsTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ScopedConversionsInMemoryServiceProvider::class];
    }
}
