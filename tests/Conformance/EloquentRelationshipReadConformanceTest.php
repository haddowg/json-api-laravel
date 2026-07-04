<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see RelationshipReadConformanceTestCase} against the **reference Eloquent provider**
 * executed as real SQL over an in-memory SQLite database. It reuses the
 * {@see EloquentWorkbenchServiceProvider} (the reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}
 * at `-128` over the SAME `app/JsonApi` resources) and seeds the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures} object graph the witness carries via
 * {@see ConformanceSeeder} — so every assertion inherited from the abstract must produce
 * the IDENTICAL result, refereeing the SQL push-down against the witness (PLAN
 * decision 9).
 *
 * It adds the two provider-asymmetric proofs that are Eloquent-only by design: the
 * load-state links-only render (the {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState}
 * reports a lazy to-many unloaded so core renders links-only), and the N+1 query-count
 * guard (the batched include stays O(1) in the number of parents).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationshipReadConformanceTest extends RelationshipReadConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return EloquentWorkbenchServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        (new ConformanceSeeder())->run();
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aLazyToManyRendersLinksOnlyUntilItIsIncluded(): void
    {
        // The albums HasMany is lazy; on a plain read it is not loaded, so the
        // EloquentRelationshipLoadState reports it unloaded and core renders links-only —
        // no `data` key, so no linkage read is triggered (the N+1-avoidance seam). The
        // eager owner-side `artist` BelongsTo, by contrast, always renders its data.
        $plain = $this->fetch('/api/artists/1');
        $plain->assertOk();
        /** @var array<string, mixed> $albums */
        $albums = $plain->json('data.relationships.albums');
        self::assertIsArray($albums);
        self::assertArrayHasKey('links', $albums);
        self::assertArrayNotHasKey('data', $albums);

        // ?include=albums wins: the same relationship now carries linkage data.
        $included = $this->fetch('/api/artists/1?include=albums');
        $included->assertOk();
        self::assertIsArray($included->json('data.relationships.albums.data'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeAcrossACollectionBatchesTheChildLoadInConstantQueries(): void
    {
        // The six seeded artists own seven albums between them, all expanded.
        $baseline = $this->countQueries(fn(): TestResponse => $this->fetch('/api/artists?include=albums'));
        $baseline['response']->assertOk();
        $included = $baseline['response']->json('included');
        self::assertIsArray($included);
        self::assertCount(7, $included);

        // Absolute ceiling: the batched read is the artists page + ONE `albums where
        // artist_id in (…)` batch + ONE batch for the included albums' eager `artist` to-one
        // (each album renders owner linkage). A per-parent OR per-included-child lazy load —
        // the N+1 the batcher exists to prevent — blows past this: before the eager-to-one
        // linkage preload landed, the SEVEN per-album `artist` loads made this request nine
        // queries, so the ceiling fails until the linkage batch is in place. A pure
        // baseline == scaled check cannot catch that: the included-child count is constant
        // between the two runs, so an O(children) term is invisible to equality alone.
        self::assertLessThanOrEqual(4, $baseline['queries']);

        // Scale BOTH terms. Three fillers that sort FIRST (created_at 1980) each own two
        // albums, so the number of included children grows (13, not 7); fifteen album-less
        // fillers that sort last pad the parent count. If EITHER the parent album load or the
        // included-album `artist` load were an N+1, the query count would climb with the
        // extra parents/children — the batcher keeps it flat.
        for ($i = 100; $i < 103; ++$i) {
            Artist::query()->create([
                'id' => $i,
                'name' => 'Early Filler ' . $i,
                'slug' => 'early-filler-' . $i,
                'track_count' => 0,
                'created_at' => new \DateTimeImmutable('1980-01-01T00:00:00+00:00'),
            ]);
            for ($j = 0; $j < 2; ++$j) {
                Album::query()->create([
                    'id' => $i * 10 + $j,
                    'artist_id' => $i,
                    'title' => 'Filler Album ' . $i . '-' . $j,
                    'status' => 'released',
                    'explicit' => false,
                    'released_at' => new \DateTimeImmutable('2015-01-01T00:00:00+00:00'),
                ]);
            }
        }
        for ($i = 200; $i < 215; ++$i) {
            Artist::query()->create([
                'id' => $i,
                'name' => 'Late Filler ' . $i,
                'slug' => 'late-filler-' . $i,
                'track_count' => 0,
                'created_at' => new \DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            ]);
        }

        $scaled = $this->countQueries(fn(): TestResponse => $this->fetch('/api/artists?include=albums'));
        $scaled['response']->assertOk();
        // The three early album-owning fillers render on the page, so more albums expand into
        // `included` (7 original + 6 filler = 13) — proof the children genuinely scaled.
        $scaledIncluded = $scaled['response']->json('included');
        self::assertIsArray($scaledIncluded);
        self::assertCount(13, $scaledIncluded);

        self::assertSame($baseline['queries'], $scaled['queries']);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function batchingChangesOnlyHowIncludesLoadNotWhatIsRendered(): void
    {
        $batcher = app(RelatedIncludeBatcher::class);

        // Enabled (the default): the effective include tree loads in a handful of batched
        // queries, and the eager owner-side `artist` linkage is preloaded in one more.
        $enabled = $this->countQueries(fn(): TestResponse => $this->fetch('/api/artists?include=albums'));
        $enabled['response']->assertOk();

        // Disabled: every include (and every eager to-one linkage) falls back to a lazy,
        // per-row load — the N+1 the batcher exists to prevent.
        $batcher->disable();

        try {
            $disabled = $this->countQueries(fn(): TestResponse => $this->fetch('/api/artists?include=albums'));
        } finally {
            $batcher->enable();
        }

        $disabled['response']->assertOk();

        // The rendered document is byte-identical with and without batching: batching only
        // changes HOW the includes are loaded (batched vs lazy), never WHAT is rendered — the
        // pure-optimization contract (PLAN decision 8, blueprint risk 1).
        self::assertSame($enabled['response']->json(), $disabled['response']->json());

        // ...but turning it off reveals the N+1: the lazy path issues strictly more queries
        // than the batched one, so a regression that silently stopped batching would show up
        // as a jump in this delta.
        self::assertGreaterThan($enabled['queries'], $disabled['queries']);
    }

    /**
     * Runs `$work` with the query log enabled and returns the response alongside the
     * number of queries it issued.
     *
     * @param \Closure(): TestResponse<\Symfony\Component\HttpFoundation\Response> $work
     *
     * @return array{response: TestResponse<\Symfony\Component\HttpFoundation\Response>, queries: int}
     */
    private function countQueries(\Closure $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $work();

        $queries = \count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['response' => $response, 'queries' => $queries];
    }
}
