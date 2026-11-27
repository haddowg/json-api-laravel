<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see EloquentMixedPivotProvidersTestCase} with the npCrates served by a provider that reads pivot rows only through the batched SPI.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentBatchOnlyCratesPivotTest extends EloquentMixedPivotProvidersTestCase
{
    protected function batchOnlyType(): string
    {
        return 'npCrates';
    }
}
