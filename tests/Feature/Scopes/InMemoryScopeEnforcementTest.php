<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The in-memory arm of {@see ScopeEnforcementTestCase}.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryScopeEnforcementTest extends ScopeEnforcementTestCase
{
    protected function catalogServiceProvider(): string
    {
        return MusicCatalogInMemoryServiceProvider::class;
    }
}
