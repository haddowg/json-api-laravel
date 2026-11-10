<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelationOrderInMemoryServiceProvider;

/**
 * {@see RelationOrderConformanceTestCase} against the in-memory witness. It holds no pivot
 * data, so its shelves declare no `picks`, and it has no relation method, so `classics`
 * reads in the books' title order.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationOrderConformanceTest extends RelationOrderConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelationOrderInMemoryServiceProvider::class;
    }

    protected function expectedPicks(): ?array
    {
        return null;
    }

    protected function expectedClassics(): array
    {
        return ['4', '1', '3'];
    }
}
