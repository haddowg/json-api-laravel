<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see RelationshipQueriesConformanceTestCase} against the **reference Eloquent provider** —
 * the SQL push-down (PLAN decision 9, ADR 0006): a windowed relationship batch is a
 * `groupLimit`/`ROW_NUMBER() OVER (PARTITION BY <parent FK> ORDER BY <relation order>, <pk>)`
 * derived-table query, with NO PHP-window fallback. The shared assertions referee it against
 * the in-memory witness on identical data.
 *
 * It adds the Eloquent-only proof that the windowed-include push-down is **O(1) in the number
 * of parents**: a per-parent lazy window — the N+1 the batcher exists to prevent — would make
 * the query count climb with the collection size; the group-limit batch keeps it flat.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationshipQueriesConformanceTest extends RelationshipQueriesConformanceTestCase
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
    #[Group('spec:profiles')]
    #[Group('spec:fetching-includes')]
    public function aWindowedIncludeAcrossACollectionIsConstantInTheNumberOfParents(): void
    {
        // A windowed collection include under the profile windows EACH parent's albums linkage
        // through ONE `groupLimit`/`ROW_NUMBER() OVER (PARTITION BY artist_id …)` push-down
        // query — not a per-parent windowed load (the N+1 the push-down exists to prevent). So
        // padding the collection with more (album-less) parents must NOT add queries: the
        // window batch is O(1) in the number of parents.
        $uri = '/api/artists?include=albums&relatedQuery[albums][sort]=-releasedAt';

        $baseline = $this->countQueries(fn(): TestResponse => $this->fetchWithProfile($uri));
        $baseline['response']->assertOk();

        // Fifteen album-less filler artists that sort LAST (created_at 2030) pad ONLY the
        // parent count — the included-child count stays at the seeded seven. A per-parent
        // windowed batch would climb with these extra parents; the group-limit push-down keeps
        // the count flat.
        for ($i = 200; $i < 215; ++$i) {
            Artist::query()->create([
                'id' => $i,
                'name' => 'Late Filler ' . $i,
                'slug' => 'late-filler-' . $i,
                'track_count' => 0,
                'created_at' => new \DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            ]);
        }

        $scaled = $this->countQueries(fn(): TestResponse => $this->fetchWithProfile($uri));
        $scaled['response']->assertOk();

        self::assertSame(
            $baseline['queries'],
            $scaled['queries'],
            'the windowed include is O(1) in the number of parents (the group-limit push-down batches the window, not a per-parent query)',
        );
    }

    /**
     * Runs `$work` with the query log enabled and returns the response alongside the number
     * of queries it issued.
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
