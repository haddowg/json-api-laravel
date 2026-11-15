<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage\InMemoryFillUsingLinkageServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see FillUsingLinkageConformanceTestCase} against the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryFillUsingLinkageConformanceTest extends FillUsingLinkageConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return InMemoryFillUsingLinkageServiceProvider::class;
    }

    protected function storedCount(string $type): int
    {
        $providers = $this->app?->make(DataProviderRegistry::class);
        \assert($providers instanceof DataProviderRegistry);
        $provider = $providers->forType($type);
        \assert($provider instanceof InMemoryDataProvider);

        return \count($provider->store()->all());
    }
}
