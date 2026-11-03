<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeyset;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetSortArmInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentSortArmInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentSortHandler;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\CursorGroup;
use Workbench\App\Models\CursorWidget;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * A cursor page ordered by a custom sort through its {@see EloquentKeysetSortArmInterface}:
 * the expression takes a column's place in the NULL=largest `ORDER BY` and the keyset
 * `WHERE`, the arm's join is added once, and a sort without a keyset arm stays a 500.
 *
 * The fixture sort orders widgets by their negated priority through a join on the
 * widget's group, binding a value in both the join and the expression so the bindings
 * must land in order.
 *
 * @internal
 */
#[CoversClass(EloquentKeyset::class)]
#[CoversClass(EloquentSortHandler::class)]
final class EloquentKeysetCustomSortTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CursorWidgetSeeder())->run();
        (new CursorGroupSeeder())->run();
    }

    #[Test]
    public function itOrdersByTheArmExpressionWithNullsAsTheLargestValue(): void
    {
        $builder = $this->widgetQuery();
        $keyset = new EloquentKeyset($builder->getModel(), new EloquentSortHandler([new NegatedPriorityArm()]));

        $keyset->orderBy($builder, [
            new KeysetColumn('negated', false, new NegatedPrioritySort()),
            new KeysetColumn('id', false),
        ]);

        self::assertSame(
            'select "cursor_widgets".* from "cursor_widgets" '
            . 'left join "cursor_groups" as "sort_group" on "sort_group"."id" = "cursor_widgets"."group_id" and "sort_group"."name" <> ? '
            . 'order by CASE WHEN (cursor_widgets.priority * ?) IS NULL THEN 1 ELSE 0 END ASC, (cursor_widgets.priority * ?) ASC, '
            . 'CASE WHEN cursor_widgets.id IS NULL THEN 1 ELSE 0 END ASC, "cursor_widgets"."id" asc',
            $builder->toSql(),
        );
        self::assertSame(['', -1, -1], $builder->getBindings());
    }

    #[Test]
    public function itComparesTheExpressionWithTheBoundaryAndJoinsOnce(): void
    {
        $builder = $this->widgetQuery();
        $keyset = new EloquentKeyset($builder->getModel(), new EloquentSortHandler([new NegatedPriorityArm()]));
        $columns = [new KeysetColumn('negated', false, new NegatedPrioritySort()), new KeysetColumn('id', false)];

        $keyset->applyAfter($builder, new CursorBoundary(['negated' => -20, 'id' => 5], true), $columns);
        $keyset->orderBy($builder, $columns);

        $sql = $builder->toSql();
        self::assertSame(1, \substr_count($sql, 'left join'));
        self::assertStringContainsString(
            'where ((((cursor_widgets.priority * ?) > ? or (cursor_widgets.priority * ?) IS NULL)) '
            . 'or ((cursor_widgets.priority * ?) = ? and ("cursor_widgets"."id" > ? or "cursor_widgets"."id" is null)))',
            $sql,
        );
        self::assertSame(['', -1, -20, -1, -1, -20, 5, -1, -1], $builder->getBindings());
    }

    #[Test]
    public function aDescendingLevelAfterANullBoundaryMatchesTheNonNullRows(): void
    {
        $builder = $this->widgetQuery();
        $keyset = new EloquentKeyset($builder->getModel(), new EloquentSortHandler([new NegatedPriorityArm()]));

        $keyset->applyAfter(
            $builder,
            new CursorBoundary(['negated' => null, 'id' => 6], true),
            [new KeysetColumn('negated', true, new NegatedPrioritySort()), new KeysetColumn('id', true)],
        );

        self::assertStringContainsString(
            'where (((cursor_widgets.priority * ?) IS NOT NULL) or ((cursor_widgets.priority * ?) IS NULL and "cursor_widgets"."id" < ?))',
            $builder->toSql(),
        );
    }

    #[Test]
    public function aRelatedCursorPageWalksTheCustomSortAcrossBoundaries(): void
    {
        $group = CursorGroup::query()->findOrFail(1);
        $provider = $this->provider([new NegatedPriorityArm()]);
        $relation = HasMany::make('widgets', 'cursorWidgets')->build();

        // Group 1 holds widgets 1, 2, 3, 4, 5 and 7 with priorities 30, 10, null, 30, 20, 10.
        $first = $provider->fetchRelatedCollection('cursorWidgets', $group, $relation, $this->criteria(new CursorWindow(2)), $this->request());
        self::assertSame(['1', '4'], $this->ids($first));

        $second = $provider->fetchRelatedCollection('cursorWidgets', $group, $relation, $this->criteria(new CursorWindow(2, after: $this->boundary($first, after: true))), $this->request());
        self::assertSame(['5', '2'], $this->ids($second));

        $third = $provider->fetchRelatedCollection('cursorWidgets', $group, $relation, $this->criteria(new CursorWindow(2, after: $this->boundary($second, after: true))), $this->request());
        self::assertSame(['7', '3'], $this->ids($third));

        $back = $provider->fetchRelatedCollection('cursorWidgets', $group, $relation, $this->criteria(new CursorWindow(2, before: $this->boundary($third, after: false))), $this->request());
        self::assertSame(['5', '2'], $this->ids($back));
    }

    #[Test]
    public function aWindowedIncludeOrdersEachPartitionByTheCustomSort(): void
    {
        $groups = CursorGroup::query()->orderBy('id')->get()->all();

        $batch = $this->provider([new NegatedPriorityArm()])->fetchRelatedCollectionBatch(
            'cursorGroups',
            \array_values($groups),
            HasMany::make('widgets', 'cursorWidgets')->build(),
            $this->criteria(new CursorWindow(2)),
            $this->request(),
        );

        self::assertSame(['1', '4'], $this->ids($batch->for('1')));
        self::assertSame(['8', '6'], $this->ids($batch->for('2')));
    }

    #[Test]
    public function aCustomSortWithoutAKeysetArmIsUnsupported(): void
    {
        $plainArm = new class implements EloquentSortArmInterface {
            public function supports(SortInterface $sort): bool
            {
                return $sort instanceof NegatedPrioritySort;
            }

            public function apply(SortInterface $sort, Builder $query, bool $descending): void {}
        };

        foreach ([[$plainArm], []] as $arms) {
            try {
                (new EloquentSortHandler($arms))->keysetExpression(new NegatedPrioritySort(), $this->widgetQuery());
                self::fail('A custom sort without a keyset arm must be unsupported.');
            } catch (UnsupportedSort $e) {
                self::assertStringContainsString('EloquentKeysetSortArmInterface', (string) $e->hint);
            }
        }
    }

    #[Test]
    public function aKeysetWithoutASortHandlerRejectsACustomLevel(): void
    {
        $this->expectException(UnsupportedSort::class);

        (new EloquentKeyset(new CursorWidget()))->orderBy($this->widgetQuery(), [new KeysetColumn('negated', false, new NegatedPrioritySort())]);
    }

    /**
     * @param list<EloquentSortArmInterface<Model>> $arms
     */
    private function provider(array $arms): EloquentDataProvider
    {
        return new EloquentDataProvider([
            'cursorGroups' => CursorGroup::class,
            'cursorWidgets' => CursorWidget::class,
        ], sortArms: $arms);
    }

    private function criteria(CursorWindow $window): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], ['negated'], [], []),
            sorts: [new NegatedPrioritySort()],
            window: $window,
        );
    }

    /**
     * @param CollectionResult<object> $result
     */
    private function boundary(CollectionResult $result, bool $after): CursorBoundary
    {
        self::assertInstanceOf(CursorCollectionResult::class, $result);
        $token = $after ? $result->cursorAfter : $result->cursorBefore;
        self::assertIsString($token);

        return (new CursorCodec())->decode($token, $after ? 'page[after]' : 'page[before]');
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $row) {
            self::assertInstanceOf(Model::class, $row);
            $key = $row->getKey();
            self::assertIsInt($key);
            $ids[] = (string) $key;
        }

        return $ids;
    }

    /**
     * @return Builder<Model>
     */
    private function widgetQuery(): Builder
    {
        return $this->widget()->newQuery();
    }

    private function widget(): Model
    {
        return new CursorWidget();
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }
}

/**
 * @internal
 */
final readonly class NegatedPrioritySort implements KeysetSortInterface
{
    public function key(): string
    {
        return 'negated';
    }

    public function keysetValue(mixed $row): mixed
    {
        $priority = $row instanceof Model ? $row->getAttribute('priority') : null;

        return \is_int($priority) ? -$priority : null;
    }
}

/**
 * @internal
 *
 * @implements EloquentKeysetSortArmInterface<Model>
 */
final class NegatedPriorityArm implements EloquentKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof NegatedPrioritySort;
    }

    public function apply(SortInterface $sort, Builder $query, bool $descending): void
    {
        throw new \LogicException('A cursor page never calls apply().');
    }

    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
    {
        $query->leftJoin('cursor_groups as sort_group', static function (JoinClause $join): void {
            $join->on('sort_group.id', '=', 'cursor_widgets.group_id')->where('sort_group.name', '<>', '');
        });

        return EloquentKeysetExpression::make('cursor_widgets.priority * ?', [-1]);
    }
}
