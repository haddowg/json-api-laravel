<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see RelationshipWriteConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the dual-provider relationship-write contract. A mutation sets the
 * plain association list on the stored object graph (immediately readable, no database), and
 * the witness keeps each pivot row beside it, so a pivot write renders its `meta.pivot` as
 * the Eloquent provider's does.
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
}
