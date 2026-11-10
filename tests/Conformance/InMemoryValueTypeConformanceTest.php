<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ValueTypeInMemoryServiceProvider;
use Workbench\App\Validation\Submission;

/**
 * The JSON type conformance cases over the in-memory provider.
 */
#[CoversNothing]
final class InMemoryValueTypeConformanceTest extends ValueTypeConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ValueTypeInMemoryServiceProvider::class;
    }

    protected function storedSubmission(string $id): Submission
    {
        $provider = $this->app?->make(ValueTypeInMemoryServiceProvider::SUBMISSIONS);
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $submission = $provider->store()->find($id);
        self::assertInstanceOf(Submission::class, $submission);

        return $submission;
    }
}
