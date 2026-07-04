<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see RelationshipQueriesConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the Relationship Queries referee. It windows every relationship
 * through core's `WindowExecutor` + the synthetic `id`-ASC PK tiebreak (`withPkTiebreak`),
 * so its pages are the reference the Eloquent `groupLimit`/`ROW_NUMBER` push-down is held to:
 * a tie-order, NULL-placement, per-parent, or count divergence between the two shows up on
 * the shared assertions. The data lives in the provider registration, so no database is
 * touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationshipQueriesConformanceTest extends RelationshipQueriesConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }
}
