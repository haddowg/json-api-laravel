<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * {@see SecurityConformanceTestCase} against the **in-memory witness** — the ground-truth
 * half of the dual-provider authorization contract. The dedicated `AlbumApiPolicy`
 * authorizes the in-memory {@see \Workbench\App\Domain\Album} POPO exactly as it
 * authorizes the Eloquent model, so the allowed/denied/`401`/inert outcomes match the
 * Eloquent half with no database touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemorySecurityConformanceTest extends SecurityConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SecurityInMemoryServiceProvider::class;
    }
}
