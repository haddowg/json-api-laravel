<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see RelationshipReadConformanceTestCase} against the **in-memory witness** — the
 * ground-truth half of the dual-provider relationship-read contract. The data lives in
 * the provider registration ({@see ConformanceInMemoryServiceProvider} seeds the shared
 * {@see \Workbench\App\Support\ConformanceFixtures} object graph), so {@see seedConformanceData()}
 * no-ops and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationshipReadConformanceTest extends RelationshipReadConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ConformanceInMemoryServiceProvider::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aLazyToManyRendersLinkageEagerlyWithoutALoadStatePredicate(): void
    {
        // The witness injects no load-state predicate, so core treats every relation as
        // loaded and renders its linkage eagerly — a plain read (no ?include) carries the
        // albums linkage data. This is the standalone default the reference provider
        // deliberately overrides with its relationLoaded() predicate (see the Eloquent
        // concrete's links-only assertion) — the seam the referee holds honest.
        $response = $this->fetch('/api/artists/1');

        $response->assertOk();
        $linkage = $response->json('data.relationships.albums.data');
        self::assertIsArray($linkage);
        self::assertCount(4, $linkage);
    }
}
