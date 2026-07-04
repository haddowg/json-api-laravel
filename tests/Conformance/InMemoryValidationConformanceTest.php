<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ValidationInMemoryServiceProvider;

/**
 * {@see ValidationConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the dual-provider validation contract. The
 * {@see ValidationInMemoryServiceProvider} registers a writable, seeded `articles`
 * provider/persister pair sharing one store, so a validated write is immediately
 * readable and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryValidationConformanceTest extends ValidationConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ValidationInMemoryServiceProvider::class;
    }
}
