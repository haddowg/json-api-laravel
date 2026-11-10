<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\PositionedInMemoryServiceProvider;

/**
 * {@see PositionedConformanceTestCase} against the in-memory witness. It holds no join pivot,
 * so `favourites` keeps its members in linkage order without positions to show.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPositionedConformanceTest extends PositionedConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PositionedInMemoryServiceProvider::class;
    }

    protected function storesPivots(): bool
    {
        return false;
    }
}
