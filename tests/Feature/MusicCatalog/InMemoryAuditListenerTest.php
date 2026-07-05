<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The in-memory arm of the audit-listener suite: the SAME cross-cutting
 * `AuditLogSubscriber` over the seeded in-memory POPO providers — the dual-provider
 * witness against the Eloquent arm.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryAuditListenerTest extends AuditListenerTestCase
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
