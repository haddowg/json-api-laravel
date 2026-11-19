<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\FieldSelectionInMemoryServiceProvider;

/**
 * The field-selection conformance cases over the in-memory provider.
 */
#[CoversNothing]
final class InMemoryFieldSelectionConformanceTest extends FieldSelectionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return FieldSelectionInMemoryServiceProvider::class;
    }
}
