<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * {@see DescribedLinksConformanceTestCase} against the in-memory music catalog.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryDescribedLinksConformanceTest extends DescribedLinksConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return MusicCatalogInMemoryServiceProvider::class;
    }
}
