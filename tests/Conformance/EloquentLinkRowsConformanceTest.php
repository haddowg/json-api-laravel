<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\LinkRows\Fixtures;
use Workbench\App\Models\Board;
use Workbench\App\Models\Note;
use Workbench\App\Models\Photo;
use Workbench\App\Providers\LinkRowsEloquentServiceProvider;

/**
 * {@see LinkRowsConformanceTestCase} against the reference Eloquent provider over in-memory
 * SQLite, which queries the link rows in SQL.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentLinkRowsConformanceTest extends LinkRowsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return LinkRowsEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

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
        foreach (Fixtures::notes() as $id => $title) {
            Note::query()->create(['id' => $id, 'title' => $title]);
        }
        foreach (Fixtures::photos() as $id => $caption) {
            Photo::query()->create(['id' => $id, 'caption' => $caption]);
        }
        foreach (Fixtures::boards() as $id => $row) {
            $board = Board::query()->create(['id' => $id, 'name' => $row['name']]);
            foreach ($row['items'] as $item) {
                $board->boardItems()->create(['item_type' => $item['type'], 'item_id' => $item['id'], 'position' => $item['position'], 'label' => $item['label']]);
            }
            foreach ($row['pins'] as $noteId => $rank) {
                $board->pins()->attach($noteId, ['rank' => $rank]);
            }
        }
    }

    #[Test]
    public function aPageOfLinkRowsIsOneQuery(): void
    {
        DB::enableQueryLog();
        $this->get('/api/boards/1/relationships/items?sort=-position&filter[label]=trip&page[size]=1', ['Accept' => self::MEDIA_TYPE])->assertOk();

        $rowQueries = \array_filter(DB::getQueryLog(), static fn(array $query): bool => \str_contains($query['query'], 'lr_board_items'));
        self::assertCount(1, $rowQueries);
        $query = \array_values($rowQueries)[0]['query'];
        self::assertStringContainsString('order by "lr_board_items"."position" desc', $query);
        self::assertStringContainsString('limit 2', $query);
    }

    #[Test]
    public function aScopedTypeIsNarrowedInTheRowQueryBeforeThePage(): void
    {
        Photo::addGlobalScope('visible', static fn(Builder $query): Builder => $query->where('id', '!=', 1));
        DB::enableQueryLog();

        try {
            /** @var list<array{type: string, id: string}> $data */
            $data = $this->get('/api/boards/1/relationships/items?page[size]=2', ['Accept' => self::MEDIA_TYPE])->assertOk()->json('data');
        } finally {
            Photo::clearBootedModels();
        }

        self::assertSame(['photos:2', 'notes:1'], \array_map(static fn(array $member): string => $member['type'] . ':' . $member['id'], $data));
        $paged = \array_filter(DB::getQueryLog(), static fn(array $query): bool => \str_contains($query['query'], 'from "lr_board_items"') && \str_contains($query['query'], 'limit'));
        self::assertCount(1, $paged);
        self::assertStringContainsString('"lr_board_items"."item_id" in', \array_values($paged)[0]['query']);
    }

    #[Test]
    public function aPageOfParentsReadsItsLinkRowsOnceAndNarrowsWithOneKeyReadAndOneAdmission(): void
    {
        Board::query()->findOrFail(2)->boardItems()->create(['item_type' => 'photos', 'item_id' => 1, 'position' => 0]);
        Photo::addGlobalScope('visible', static fn(Builder $query): Builder => $query->where('id', '!=', 1));
        DB::enableQueryLog();

        try {
            $response = $this->get('/api/boards', ['Accept' => self::MEDIA_TYPE])->assertOk();
        } finally {
            Photo::clearBootedModels();
        }

        /** @var list<array{type: string, id: string}> $first */
        $first = $response->json('data.0.relationships.items.data');
        self::assertSame(['photos:2', 'notes:1', 'notes:2'], \array_map(static fn(array $member): string => $member['type'] . ':' . $member['id'], $first));
        self::assertSame([], $response->json('data.1.relationships.items.data'));

        $queries = \array_column(DB::getQueryLog(), 'query');
        $keyReads = \array_filter($queries, static fn(string $query): bool => \str_contains($query, 'select distinct') && \str_contains($query, '"lr_board_items"'));
        $rowReads = \array_filter($queries, static fn(string $query): bool => \str_starts_with($query, 'select * from "lr_board_items"'));
        $admissions = \array_filter($queries, static fn(string $query): bool => \str_contains($query, 'from "lr_photos"'));
        // One admission of the photos for `items`; the closure relation `looseItems` admits its
        // own photos once more, also for the whole page.
        // One key read and one row read of the link rows for the whole page.
        self::assertCount(1, $keyReads, \implode("\n", $queries));
        self::assertCount(1, $rowReads, \implode("\n", $queries));
        self::assertCount(2, $admissions, \implode("\n", $queries));
    }
}
