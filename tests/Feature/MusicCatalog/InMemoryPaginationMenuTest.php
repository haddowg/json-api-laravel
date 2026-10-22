<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The in-memory arm of the pagination-menu suite: the same `tracks` menu over the
 * seeded in-memory providers, the dual-provider witness against the Eloquent arm.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPaginationMenuTest extends PaginationMenuTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogInMemoryServiceProvider::class,
        ];
    }
}
