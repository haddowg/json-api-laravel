<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\CursorWithCountConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorBoardSeeder;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see CursorWithCountConformanceTestCase} against the Eloquent provider on sqlite, plus
 * the SQL behind a counted cursor page: one `COUNT` over the filtered collection, with no
 * keyset bound and no `LIMIT`, beside the usual `limit + 1` keyset fetch.
 */
#[CoversNothing]
final class EloquentCursorWithCountConformanceTest extends CursorWithCountConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CursorWithCountConformanceEloquentServiceProvider::class;
    }

    protected function defineEnvironment($app): void
    {
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
        (new CursorWidgetSeeder())->run();
        (new CursorGroupSeeder())->run();
        (new CursorBoardSeeder())->run();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function countedCursorPages(): iterable
    {
        yield 'primary' => ['/api/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id'];
        yield 'related' => ['/api/cursorGroups/1/widgets?withCount=_self_&page[size]=2&filter[category]=guide'];
        yield 'pivot related' => ['/api/cursorBoards/1/widgets?withCount=_self_&page[size]=2&filter[category]=guide'];
        yield 'linkage' => ['/api/cursorGroups/1/relationships/widgets?withCount=_self_&page[size]=2&filter[category]=guide'];
        yield 'pivot linkage' => ['/api/cursorBoards/1/relationships/widgets?withCount=_self_&page[size]=2&filter[category]=guide'];
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[DataProvider('countedCursorPages')]
    public function theTotalIsOneCountQueryBesideTheKeysetFetch(string $path): void
    {
        $statements = $this->captureStatements($path);

        $counts = \array_values(\array_filter($statements, static fn(string $s): bool => \stripos($s, 'count(') !== false));
        self::assertCount(1, $counts, "the total is one COUNT query:\n" . \implode("\n", $statements));
        self::assertStringNotContainsStringIgnoringCase('limit', $counts[0], 'the count is not windowed');

        foreach ($statements as $statement) {
            if (\stripos($statement, 'count(') !== false || !\str_contains($statement, 'from "cursor_widgets"')) {
                continue;
            }
            self::assertMatchesRegularExpression('/limit 3\b/i', $statement, 'every widget row read is the limit + 1 keyset probe, never the whole collection');
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountedPageReachedByACursorStillCountsOnce(): void
    {
        $first = $this->countedDocument('/api/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news');
        $links = $first['links'] ?? null;
        self::assertIsArray($links);
        $next = $links['next'] ?? null;
        $href = \is_array($next) ? ($next['href'] ?? null) : $next;
        self::assertIsString($href);
        $parts = \parse_url($href);
        self::assertIsArray($parts);

        $statements = $this->captureStatements(($parts['path'] ?? '') . '?' . ($parts['query'] ?? ''));

        self::assertCount(1, \array_filter($statements, static fn(string $s): bool => \stripos($s, 'count(') !== false));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anUncountedCursorPageRunsNoCount(): void
    {
        $statements = $this->captureStatements('/api/cursorWidgets?page[size]=2&filter[category]=news');

        self::assertSame([], \array_values(\array_filter($statements, static fn(string $s): bool => \stripos($s, 'count(') !== false)));
    }

    /**
     * @return list<string>
     */
    private function captureStatements(string $path): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->countedDocument($path);
        } finally {
            DB::disableQueryLog();
        }

        return \array_values(\array_map(static fn(array $entry): string => (string) $entry['query'], DB::getQueryLog()));
    }
}
