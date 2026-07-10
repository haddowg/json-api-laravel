<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\CursorBoard;
use Workbench\App\Models\CursorGroup;
use Workbench\App\Models\CursorWidget;
use Workbench\Database\Seeders\CursorBoardSeeder;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * The Eloquent provider's WINDOWED multi-parent relation batch for a CURSOR (keyset)
 * included relation — the N→1 collapse (ADR 0026). A cursor-resolved include is a
 * boundaryless FIRST cursor page per parent, and it now pushes down to the SAME single
 * `Builder::groupLimit` query the offset include uses (`ROW_NUMBER() OVER (PARTITION BY
 * <parent FK> ORDER BY <keyset>)` capped at `limit + 1` per partition), only ordered by
 * the resolved keyset columns instead of the sort + hardcoded id tiebreak.
 *
 * These tests PIN the generated SQL (the row-number window, the partition column, the
 * forced NULL=largest keyset ordering term, and the count-free `limit + 1` cap) and prove
 * exactly ONE statement carries the window for a page of parents — the optimization the
 * per-parent loop {@see EloquentWindowedRelationBatchTest} refereed for offset now applies
 * to cursor. Byte-identical rendered output against the in-memory witness is the
 * {@see \haddowg\JsonApiLaravel\Tests\Conformance\CursorIncludeConformanceTestCase} suites'
 * concern; here the concern is the SQL shape and the statement count.
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
final class EloquentWindowedCursorRelationBatchTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CursorWidgetSeeder())->run();
        (new CursorGroupSeeder())->run();
        (new CursorBoardSeeder())->run();
    }

    // --- the generated SQL (one group-limit window, keyset order, count-free cap) ---

    #[Test]
    public function itCollapsesACursorResolvedIncludeToOneKeysetWindowPartitionedByTheParentForeignKey(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->groupProvider()->fetchRelatedCollectionBatch(
            'cursorGroups',
            $this->allGroups(),
            HasMany::make('widgets', 'cursorWidgets'),
            $this->cursorCriteria('priority'),
            $this->request(),
        );

        // Exactly ONE statement carries the window for the whole page of parents (the N→1
        // collapse): the per-parent loop would have issued one keyset SELECT per group.
        self::assertCount(1, $this->windowStatements(), 'a cursor-resolved include is ONE window query');

        $sql = $this->normalise($this->windowSql());

        // The derived-table ROW_NUMBER window, partitioned by the qualified parent FK.
        self::assertStringContainsString('row_number() over (partition by "cursor_widgets"."group_id"', $sql);
        // The forced NULL=largest keyset ordering term (the `CASE … IS NULL …` #21 feared)
        // composed verbatim into the OVER clause, then the plain column order, then the PK
        // tiebreak — all ascending (priority asc, id asc). The raw CASE term qualifies the
        // column unquoted (its verbatim orderByRaw form), the plain term quoted — exactly
        // the shape the per-parent runCursor keyset emits, so the two mint byte-identically.
        self::assertStringContainsString(
            'order by case when cursor_widgets.priority is null then 1 else 0 end asc, '
            . '"cursor_widgets"."priority" asc, '
            . 'case when cursor_widgets.id is null then 1 else 0 end asc, '
            . '"cursor_widgets"."id" asc',
            $sql,
        );
        // A cursor page is count-free by definition, so the window probes limit + 1 (2 + 1 =
        // 3) rows per partition for the hasMore signal — never a bare `limit`.
        self::assertStringContainsString('as "laravel_table" where "laravel_row" <= 3', $sql);
    }

    #[Test]
    public function theKeysetPkTiebreakRidesTheLastActiveDirectionNotAHardcodedIdAsc(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // A DESCENDING active sort makes the appended PK tiebreak descending too (the keyset
        // rule), unlike the offset path which always appends id ASC — so the window's OVER
        // clause carries `id desc`, proving the cursor branch orders by the resolved keyset
        // columns, never a hardcoded tiebreak.
        $this->groupProvider()->fetchRelatedCollectionBatch(
            'cursorGroups',
            $this->allGroups(),
            HasMany::make('widgets', 'cursorWidgets'),
            $this->cursorCriteria('-priority'),
            $this->request(),
        );

        $sql = $this->normalise($this->windowSql());

        self::assertStringContainsString(
            'order by case when cursor_widgets.priority is null then 1 else 0 end desc, '
            . '"cursor_widgets"."priority" desc, '
            . 'case when cursor_widgets.id is null then 1 else 0 end desc, '
            . '"cursor_widgets"."id" desc',
            $sql,
        );
    }

    // --- belongsToMany cursor include through the pivot join ------------------

    #[Test]
    public function itWindowsABelongsToManyCursorIncludeThroughThePivotJoinInOneQuery(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->boardProvider()->fetchRelatedCollectionBatch(
            'cursorBoards',
            $this->allBoards(),
            BelongsToMany::make('widgets', 'cursorWidgets'),
            $this->cursorCriteria('priority'),
            $this->request(),
        );

        self::assertCount(1, $this->windowStatements(), 'a pivot cursor include is ONE window query');

        $sql = $this->normalise($this->windowSql());

        // The group-limit partitions by the qualified foreign PIVOT key; the keyset order
        // still qualifies off the RELATED table (never the pivot), so the cursor walks the
        // widget columns while the partition rides the join (ADR 0017).
        self::assertStringContainsString('row_number() over (partition by "cursor_board_widget"."board_id"', $sql);
        self::assertStringContainsString('case when cursor_widgets.priority is null then 1 else 0 end asc', $sql);
        self::assertStringContainsString('as "laravel_table" where "laravel_row" <= 3', $sql);
    }

    // --- correctness: the batch mints a per-parent cursor page ----------------

    #[Test]
    public function itMintsAForwardCursorPagePerParentFromTheSingleWindow(): void
    {
        // priority asc, id asc; group 1 owns widgets 1,2,3,4,5,7 → order 2(10),7(10),5(20),
        // 1(30),4(30),3(null) → page 1 = [2, 7] with a further page; group 2 owns 6,8 →
        // order 8(20),6(null) → page 1 = [8, 6] exactly the page, no further page.
        $batch = $this->groupProvider()->fetchRelatedCollectionBatch(
            'cursorGroups',
            $this->allGroups(),
            HasMany::make('widgets', 'cursorWidgets'),
            $this->cursorCriteria('priority'),
            $this->request(),
        );

        $group1 = $batch->for('1');
        self::assertSame(['2', '7'], $this->ids($group1));
        self::assertInstanceOf(CursorCollectionResult::class, $group1);
        self::assertTrue($group1->hasMore);
        self::assertFalse($group1->hasPrevious);
        self::assertIsString($group1->cursorAfter);

        $group2 = $batch->for('2');
        self::assertSame(['8', '6'], $this->ids($group2));
        self::assertInstanceOf(CursorCollectionResult::class, $group2);
        self::assertFalse($group2->hasMore);
    }

    private function groupProvider(): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'cursorGroups' => CursorGroup::class,
            'cursorWidgets' => CursorWidget::class,
        ]);
    }

    private function boardProvider(): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'cursorBoards' => CursorBoard::class,
            'cursorWidgets' => CursorWidget::class,
        ]);
    }

    /**
     * A boundaryless FIRST cursor page (an include carries no cursor token) over the widget
     * resource's own sortable vocabulary — `$field` is the requested `?sort` (a leading `-`
     * flips it), resolved to the keyset columns exactly as the related endpoint resolves them.
     */
    private function cursorCriteria(string $field): CollectionCriteria
    {
        $key = \str_starts_with($field, '-') ? \substr($field, 1) : $field;

        return new CollectionCriteria(
            new QueryParameters([], [], [$field], [], []),
            sorts: [SortByField::make($key, $key)],
            window: new CursorWindow(2),
        );
    }

    /**
     * @return list<CursorGroup>
     */
    private function allGroups(): array
    {
        /** @var list<CursorGroup> $groups */
        $groups = CursorGroup::query()->orderBy('id')->get()->all();

        return $groups;
    }

    /**
     * @return list<CursorBoard>
     */
    private function allBoards(): array
    {
        /** @var list<CursorBoard> $boards */
        $boards = CursorBoard::query()->orderBy('id')->get()->all();

        return $boards;
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }

    /**
     * The logged group-limit statements (those carrying `row_number`) — one per windowed
     * relation when the cursor include collapses to a single query.
     *
     * @return list<array<string, mixed>>
     */
    private function windowStatements(): array
    {
        $statements = [];
        foreach (DB::getQueryLog() as $entry) {
            $query = \is_string($entry['query'] ?? null) ? $entry['query'] : '';
            if (\str_contains(\strtolower($query), 'row_number')) {
                $statements[] = $entry;
            }
        }

        return $statements;
    }

    /**
     * The SQL of the (single) windowed group-limit query.
     */
    private function windowSql(): string
    {
        $statements = $this->windowStatements();
        if ($statements === []) {
            self::fail('No windowed group-limit query (row_number) was logged.');
        }

        $query = $statements[0]['query'] ?? null;

        return \is_string($query) ? $query : '';
    }

    /**
     * Lowercases and collapses whitespace so the SQL assertions are robust to formatting.
     */
    private function normalise(string $sql): string
    {
        return (string) \preg_replace('/\s+/', ' ', \strtolower($sql));
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(CursorWidget::class, $item);
            /** @var mixed $key */
            $key = $item->getKey();
            $ids[] = \is_scalar($key) ? (string) $key : '';
        }

        return $ids;
    }
}
