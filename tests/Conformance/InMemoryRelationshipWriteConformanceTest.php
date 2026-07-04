<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see RelationshipWriteConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the dual-provider relationship-write contract. A mutation sets the
 * plain association list on the stored object graph (immediately readable, no database), and
 * the witness stores NO pivot meta (the documented boundary — a pivot column needs an
 * association entity it cannot model), so a pivot write still changes the membership but
 * renders NO `meta.pivot` (ADR 0008), asserted through {@see providerRendersPivotMeta()}.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationshipWriteConformanceTest extends RelationshipWriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }

    protected function providerRendersPivotMeta(): bool
    {
        return false;
    }
}
