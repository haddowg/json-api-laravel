<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\TraversesPath;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\TargetsTypeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;

/**
 * Executes core's filter value objects against an Eloquent query {@see Builder},
 * pushing each predicate down to SQL (`where`, parameter-bound). Semantics mirror
 * core's in-memory {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler}
 * — the conformance witness — so the same spec test passes on both providers:
 *
 * - {@see Where} comparison operators map to their SQL equivalents; `like`,
 *   `starts` and `ends` are the three wildcard-`LIKE` string strategies — all
 *   **case-insensitive for ASCII** (the reference contract — the in-memory handler
 *   folds via `stripos`/`str_ends_with`): the value is wildcard-escaped and both
 *   sides are `LOWER()`ed via a `whereRaw` with an explicit `ESCAPE '!'`, so the
 *   behaviour does not depend on the platform's `LIKE` collation (PostgreSQL's
 *   `LIKE` is case-sensitive; SQLite/MySQL fold ASCII only). `==`/`===` both map
 *   to `=`, `!=` to `<>`.
 * - {@see Range} (and {@see DateRange}, which extends it) is the structured
 *   `min`/`max` filter: two push-down `where` predicates (`>= min`, `<= max`) over
 *   the present bounds on the SAME query — one query, no join, no subquery.
 * - {@see WhereIn}/{@see WhereIdIn} with an empty value list match nothing
 *   (`whereRaw('1 = 0')`); the negated variants then match everything (no-op).
 * - {@see WhereNull}/{@see WhereNotNull} ignore the request value entirely.
 * - {@see WhereAll}/{@see WhereAny} are server-composed boolean groups: each child
 *   is applied inside a nested `where(fn ($q) => …)` closure — combining with `AND`
 *   ({@see WhereAll}) or `orWhere` ({@see WhereAny}) — fanning the group's value
 *   uniformly to every child. So a fanning group is a multi-column search and a
 *   fixed-child group a canned toggle, and groups nest (a group child re-enters this
 *   dispatch). A `->fixed()` {@see Where} needs no arm of its own: its pinned value
 *   rides the existing `deserialize` seam, so the {@see Where} arm runs it unchanged.
 * - {@see WhereHas}/{@see WhereDoesntHave}, {@see WhereThrough} and
 *   {@see WhereThroughIn} are relationship-existence filters: Eloquent's
 *   `whereHas`/`whereDoesntHave` compile to a correlated `EXISTS`/`NOT EXISTS`
 *   subquery (set-membership, never a fetch-join, so the primary rows are neither
 *   duplicated nor need a `DISTINCT`), and a traversal path drives a nested
 *   `whereHas` whose leaf closure applies the comparison, or the `IN` list, on the
 *   final relation. A path resolved to a relationship leaf compares the related
 *   model's key with the decoded id.
 *
 * Columns come from the server-side resource declaration, never the client, and
 * are validated as identifier paths and qualified against the model's table before
 * being interpolated into a `LIKE`/raw fragment; values are always bound.
 *
 * A custom {@see FilterInterface} that carries its own query fragment implements
 * {@see AppliesToEloquentQueryBuilder} — the handler runs it **before** the arm registry,
 * so a one-off, dependency-free custom filter needs no service. Otherwise a custom filter
 * is delegated to a registered {@see EloquentFilterArmInterface} (constructor-injected,
 * first {@see EloquentFilterArmInterface::supports()} match wins) before
 * {@see UnsupportedFilter} is raised — the Eloquent half of the framework's
 * extensible-handler seam.
 *
 * @implements FilterHandlerInterface<Builder<Model>>
 */
final class EloquentFilterHandler implements FilterHandlerInterface, TargetsTypeInterface
{
    /**
     * Data-layer-specific remediation appended to the core {@see UnsupportedFilter}
     * message when a custom filter reaches this handler with no arm to run it.
     */
    private const string ARM_HINT = 'To run a custom filter on the Eloquent provider, register an EloquentFilterArmInterface on the EloquentDataProvider (constructor $filterArms).';

    /**
     * @var list<EloquentFilterArmInterface<Model>>
     */
    private readonly array $arms;

    /**
     * @var EloquentBelongsToMany<Model, Model, *, *>|null
     */
    private ?EloquentBelongsToMany $pivot = null;

    /**
     * Whether a `pivot.`-prefixed column names a column of the query's own model, the link row
     * an identified `MorphToMany` queries.
     */
    private bool $rows = false;

    private readonly NaiveDateTimeStorage $dates;

    /**
     * The type the filters run against, whose encoder decodes an id compared with the
     * query model's key.
     */
    private ?string $type = null;

    /**
     * The types the declaration of the filters being applied names, which settle the
     * encoder of a model several types serve.
     *
     * @var list<string>
     */
    private array $named = [];

    /**
     * @param iterable<EloquentFilterArmInterface<Model>>                                  $arms       author arms for custom filter types, consulted in order
     * @param ModelEncoders|null                                                     $encoders   picks the id encoder a compared key decodes with
     */
    public function __construct(iterable $arms = [], private readonly ?ModelEncoders $encoders = null)
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
        $this->dates = new NaiveDateTimeStorage();
    }

    /**
     * A copy of this handler that resolves a `pivot.`-prefixed column to `$relation`'s join
     * table, for a query built off that `belongsToMany`.
     *
     * @param EloquentBelongsToMany<Model, Model, *, *> $relation
     */
    public function forPivot(EloquentBelongsToMany $relation): self
    {
        $handler = clone $this;
        $handler->pivot = $relation;

        return $handler;
    }

    public function forType(string $type, array $named = []): static
    {
        $handler = clone $this;
        $handler->type = $type;
        $handler->named = $named !== [] ? $named : [$type];

        return $handler;
    }

    /**
     * A copy of this handler that reads a `pivot.`-prefixed column off the query's own model,
     * for a query over an identified `MorphToMany`'s link rows.
     */
    public function forRows(): self
    {
        $handler = clone $this;
        $handler->rows = true;

        return $handler;
    }

    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        if (!$query instanceof Builder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                Builder::class,
                \get_debug_type($query),
            ));
        }

        // Each arm mutates the builder in place (Eloquent's `where*` are fluent
        // setters); the same `$query` is returned so the applier threads one builder.
        match (true) {
            // A pivot relation's `pivot.`-prefixed relationship filters start from the row
            // that links the parent to each member.
            $this->startsFromRow($filter) => $this->applyOnRow($query, $filter, $value),
            // Server-composed boolean groups: recurse each child into a nested
            // where()/orWhere() closure, fanning the group's value uniformly.
            $filter instanceof WhereAll => $this->whereAll($query, $filter, $value),
            $filter instanceof WhereAny => $this->whereAny($query, $filter, $value),
            $filter instanceof Where => $this->where($filter, $query, $value),
            $filter instanceof WhereIn => $this->whereIn($query, $filter->column, $this->members($filter, $value), false),
            $filter instanceof WhereNotIn => $this->whereIn($query, $filter->column, $this->members($filter, $value), true),
            $filter instanceof WhereIdIn => $this->whereIn($query, $filter->column, $this->idMembers($query, $filter, $value), false),
            $filter instanceof WhereIdNotIn => $this->whereIn($query, $filter->column, $this->idMembers($query, $filter, $value), true),
            $filter instanceof WhereNull => $query->whereNull($this->qualify($query, $filter->column)),
            $filter instanceof WhereNotNull => $query->whereNotNull($this->qualify($query, $filter->column)),
            $filter instanceof WhereThrough => $this->whereThrough($query, $filter, $value),
            $filter instanceof WhereThroughIn => $this->whereThroughIn($query, $filter, $value),
            $filter instanceof WhereHas => $query->whereHas($filter->relationship),
            $filter instanceof WhereDoesntHave => $query->whereDoesntHave($filter->relationship),
            // Range (and DateRange, which extends it): two push-down where predicates.
            $filter instanceof Range => $this->range($query, $filter, $value),
            // A self-applying filter carries its own Eloquent query fragment (a named
            // scope, a where closure) — consulted before the arm registry, so no
            // separate arm service is needed.
            $filter instanceof AppliesToEloquentQueryBuilder => $filter->applyToQueryBuilder($query, $value),
            default => $this->applyArm($filter, $query, $value),
        };

        return $query;
    }

    /**
     * Delegates a custom {@see FilterInterface} to the first registered
     * {@see EloquentFilterArmInterface} that supports it; {@see UnsupportedFilter}
     * when none does.
     *
     * @param Builder<Model> $query
     */
    private function applyArm(FilterInterface $filter, Builder $query, mixed $value): void
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($filter)) {
                $arm->apply($filter, $query, $value);

                return;
            }
        }

        throw new UnsupportedFilter($filter, self::ARM_HINT);
    }

    /**
     * @param Builder<Model> $query
     */
    private function where(Where $filter, Builder $query, mixed $value): void
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;
        if ($this->isKeyOf($query->getModel(), $filter->column)) {
            $expected = $this->storageKey($query->getModel(), $expected, $filter, $filter->column, $this->type);
        }

        $this->applyComparison($query, $filter->column, $filter->operator, $expected, $filter->key());
    }

    /**
     * AND group ({@see WhereAll}): a nested `where(fn ($q) => …)` closure inside
     * which every child is applied — each in its own nested closure so a child
     * that is itself an OR group keeps its precedence — combining with `AND`. Every
     * child re-enters {@see apply()} with the **group's** value (fanned uniformly),
     * so a fixed child ignores it, a fanning child consumes it, and a nested group
     * re-enters this dispatch. An empty group adds no predicate (the AND identity).
     *
     * @param Builder<Model> $query
     */
    private function whereAll(Builder $query, WhereAll $filter, mixed $value): void
    {
        if ($filter->children === []) {
            return;
        }

        $query->where(function (Builder $group) use ($filter, $value): void {
            foreach ($filter->children as $child) {
                $group->where(function (Builder $inner) use ($child, $value): void {
                    $this->apply($child, $inner, $value);
                });
            }
        });
    }

    /**
     * OR group ({@see WhereAny}): a nested `where(fn ($q) => …)` closure inside
     * which every child is applied through `orWhere`, each in its own nested closure
     * — so a value-carrying group is a multi-column search (`filter[q]=foo` →
     * `col1 LIKE foo OR col2 LIKE foo`) and a fixed-child group a canned toggle. The
     * group's value is fanned to every child; a nested group re-enters this dispatch.
     * An empty group matches nothing (the OR identity), mirroring the in-memory witness.
     *
     * @param Builder<Model> $query
     */
    private function whereAny(Builder $query, WhereAny $filter, mixed $value): void
    {
        if ($filter->children === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $group) use ($filter, $value): void {
            foreach ($filter->children as $child) {
                $group->orWhere(function (Builder $inner) use ($child, $value): void {
                    $this->apply($child, $inner, $value);
                });
            }
        });
    }

    /**
     * The dotted-traversal existence filter ({@see WhereThrough}): an `EXISTS-ANY`
     * semi-join. Eloquent's `whereHas` accepts a dotted `a.b.c` relation string for
     * nested existence; the leaf attribute is split off the last segment and the
     * comparison applied in the closure on the final relation's query. A path resolved to
     * a relationship leaf traverses every segment and compares the related model's key
     * with the decoded id instead.
     *
     * @param Builder<Model> $query
     */
    private function whereThrough(Builder $query, WhereThrough $filter, mixed $value): void
    {
        $expected = $filter->storageValue($value);
        [$relation, $leaf] = $this->throughPath($filter);

        $query->whereHas(
            $relation,
            function (Builder $sub) use ($leaf, $filter, $expected): void {
                $belongsTo = $leaf !== null ? BelongsToLeaf::of($sub->getModel(), $leaf) : null;
                if ($belongsTo !== null) {
                    $operator = \in_array($filter->operator, ['!=', '<>'], true) ? '<>' : '=';
                    $this->compare($sub, $belongsTo->getForeignKeyName(), $operator, $this->storageKey($belongsTo->getRelated(), $expected, $filter, $filter->throughPath()));

                    return;
                }

                $this->applyComparison($sub, $leaf ?? $sub->getModel()->getKeyName(), $filter->operator, $expected, $filter->key());
            },
        );
    }

    /**
     * The dotted-traversal set filter ({@see WhereThroughIn}): one `whereHas` chain over
     * the path whose leaf is a `whereIn` over the list, so the list is one correlated
     * `EXISTS` with an `IN`, never one per value. An empty list matches nothing.
     *
     * @param Builder<Model> $query
     */
    private function whereThroughIn(Builder $query, WhereThroughIn $filter, mixed $value): void
    {
        $values = $filter->storageValues($value);
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        [$relation, $leaf] = $this->throughPath($filter);

        $query->whereHas(
            $relation,
            function (Builder $sub) use ($leaf, $values, $filter): void {
                $belongsTo = $leaf !== null ? BelongsToLeaf::of($sub->getModel(), $leaf) : null;
                if ($belongsTo !== null) {
                    $related = $belongsTo->getRelated();
                    $this->whereIn($sub, $belongsTo->getForeignKeyName(), \array_map(fn(mixed $value): mixed => $this->storageKey($related, $value, $filter, $filter->throughPath()), $values), false);

                    return;
                }

                $this->whereIn($sub, $leaf ?? $sub->getModel()->getKeyName(), $values, false);
            },
        );
    }

    /**
     * The relation chain a traversal filter's `whereHas` walks and the column it compares
     * at the end, or `null` for the related model's key when the path ends on a
     * relationship. A path ending on a field needs at least one relationship before it.
     *
     * @return array{string, ?string}
     */
    private function throughPath(TraversesPath $filter): array
    {
        $path = $filter->resolvedPath() ?? ThroughPath::toField($filter->throughPath());
        $segments = $path->segments;

        if ($path->endsOnRelation()) {
            return [\implode('.', $segments), null];
        }

        if (\count($segments) < 2) {
            throw new \LogicException(\sprintf(
                'Filter "%s" needs a dotted path "relationship.attribute" or a path ending on a relationship; got "%s".',
                $filter->key(),
                $filter->throughPath(),
            ));
        }

        return [\implode('.', \array_slice($segments, 0, -1)), $segments[\count($segments) - 1]];
    }

    /**
     * Whether `$filter` is a relationship filter whose relationship or path starts with
     * `pivot.`, on a query built off a pivot `belongsToMany`.
     */
    private function startsFromRow(FilterInterface $filter): bool
    {
        if ($this->pivot === null) {
            return false;
        }

        $path = match (true) {
            $filter instanceof WhereHas, $filter instanceof WhereDoesntHave => $filter->relationship,
            $filter instanceof WhereThrough, $filter instanceof WhereThroughIn => $filter->path,
            default => '',
        };

        return \str_starts_with($path, RelationCriteriaFactory::PIVOT_PREFIX);
    }

    /**
     * Applies a relationship filter that starts from the pivot row: an `EXISTS` over the
     * first relation of the pivot model, built as Eloquent builds a `whereHas` from the
     * pivot model, so it correlates to the join table row the query already holds. The
     * rest of the path walks on from there, and a path that ends on a relation compares
     * the key of the model it reaches, as stored.
     *
     * @param Builder<Model> $query
     */
    private function applyOnRow(Builder $query, FilterInterface $filter, mixed $value): void
    {
        \assert($this->pivot !== null);
        [$path, $negate] = match (true) {
            $filter instanceof WhereHas => [$filter->relationship, false],
            $filter instanceof WhereDoesntHave => [$filter->relationship, true],
            $filter instanceof WhereThrough => [$filter->path, false],
            $filter instanceof WhereThroughIn => [$filter->path, false],
            default => throw new \LogicException('Only relationship filters start from the pivot row.'),
        };

        $values = [];
        if ($filter instanceof WhereThroughIn) {
            $values = $filter->list->members($value);
            if ($values === []) {
                $query->whereRaw('1 = 0');

                return;
            }
        }

        $segments = \explode('.', \substr($path, \strlen(RelationCriteriaFactory::PIVOT_PREFIX)));
        $name = \array_shift($segments);
        $row = $this->pivot->newPivot();
        $relation = \method_exists($row, $name) ? $row->{$name}() : null;
        if (!$relation instanceof EloquentRelation) {
            throw new \LogicException(\sprintf('Filter "%s" starts from the pivot row, but "%s" is not a relation of %s.', $filter->key(), $name, $row::class));
        }

        /** @var Builder<Model> $rowQuery */
        $rowQuery = $row->newQueryWithoutRelationships();
        /** @var Builder<Model> $exists */
        $exists = $relation->getRelationExistenceQuery($relation->getRelated()->newQueryWithoutRelationships(), $rowQuery);

        $leaf = match (true) {
            $filter instanceof WhereThrough => fn(Builder $sub, ?string $column) => $this->compareLeaf($sub, $column, $filter, $value),
            $filter instanceof WhereThroughIn => fn(Builder $sub, ?string $column) => $this->leafIn($sub, $column, $filter, $values),
            default => null,
        };

        if ($leaf === null) {
            if ($segments !== []) {
                $exists->whereHas(\implode('.', $segments));
            }
        } else {
            $column = \array_pop($segments);
            if ($segments === []) {
                $leaf($exists, $column);
            } else {
                $exists->whereHas(\implode('.', $segments), static fn(Builder $sub) => $leaf($sub, $column));
            }
        }

        $query->addWhereExistsQuery($exists->toBase(), 'and', $negate);
    }

    /**
     * A row-rooted traversal's comparison at the end of its path: a column, the foreign key
     * of a `belongsTo` it names, or, when the path ends on a relation, the key of the model
     * reached, each value in the form the model stores.
     *
     * @param Builder<Model> $query
     */
    private function compareLeaf(Builder $query, ?string $column, WhereThrough $filter, mixed $value): void
    {
        $operator = $filter->operator;
        $belongsTo = $column !== null ? BelongsToLeaf::of($query->getModel(), $column) : null;
        if ($column !== null && $belongsTo === null) {
            $this->applyComparison($query, $column, $operator, $value, $filter->key());

            return;
        }

        $model = $belongsTo?->getRelated() ?? $query->getModel();
        $this->compare(
            $query,
            $belongsTo?->getForeignKeyName() ?? $model->getKeyName(),
            \in_array($operator, ['!=', '<>'], true) ? '<>' : '=',
            $this->storageKey($model, $value, $filter, $filter->path),
        );
    }

    /**
     * {@see compareLeaf()} for a list of values, as an `IN`.
     *
     * @param Builder<Model> $query
     * @param list<mixed>    $values
     */
    private function leafIn(Builder $query, ?string $column, WhereThroughIn $filter, array $values): void
    {
        $belongsTo = $column !== null ? BelongsToLeaf::of($query->getModel(), $column) : null;
        if ($column !== null && $belongsTo === null) {
            $this->whereIn($query, $column, $values, false);

            return;
        }

        $model = $belongsTo?->getRelated() ?? $query->getModel();
        $this->whereIn($query, $belongsTo?->getForeignKeyName() ?? $model->getKeyName(), \array_map(fn(mixed $value): mixed => $this->storageKey($model, $value, $filter, $filter->path), $values), false);
    }

    /**
     * A set filter's members matched against the model key: each a wire id decoded through
     * the id encoder of the type the model serves, so a binary UUID key matches its
     * canonical string. A value the encoder doesn't recognise is compared as sent.
     *
     * @param Builder<Model> $query
     *
     * @return list<mixed>
     */
    private function idMembers(Builder $query, WhereIdIn|WhereIdNotIn $filter, mixed $value): array
    {
        $members = $this->members($filter, $value);
        if (!$this->isKeyOf($query->getModel(), $filter->column)) {
            return $members;
        }

        $model = $query->getModel();

        return \array_map(fn(mixed $member): mixed => $this->storageKey($model, $member, $filter, $filter->column, $this->type), $members);
    }

    private function isKeyOf(Model $model, string $column): bool
    {
        return $this->pivot === null && !$this->rows && ($column === $model->getKeyName() || $column === $model->getQualifiedKeyName());
    }

    /**
     * `$value` decoded through the id encoder {@see ModelEncoders} picks for `$model`: that
     * of `$type`, the type the comparison targets, when it serves the model, otherwise the
     * one the types serving it, or the types the declaration names, agree on. As sent when
     * the encoder doesn't recognise it. A model no type with an encoder serves, such as a
     * target only a pivot model reaches, stores its key through its own cast when it
     * declares one.
     *
     * @throws \LogicException when the types serving the model disagree and nothing names one
     */
    private function storageKey(Model $model, mixed $value, FilterInterface $filter, string $path, ?string $type = null): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        try {
            $encoder = $this->encoders?->for($model::class, $type, $this->named);
        } catch (AmbiguousModelEncoder $e) {
            throw new \LogicException(AmbiguousModelEncoder::forFilter($this->type ?? '', $filter->key(), $path, $e->model, $e->candidates), 0, $e);
        }

        return $encoder !== null ? $encoder->decode($value) ?? $value : $this->castKey($model, $value);
    }

    /**
     * `$value` as `$model` stores its key, set through the cast on it when it declares one.
     */
    private function castKey(Model $model, string $value): mixed
    {
        if (!$model->hasCast($model->getKeyName())) {
            return $value;
        }

        try {
            $stored = $model->newInstance();
            $stored->setAttribute($model->getKeyName(), $value);

            return $stored->getAttributes()[$model->getKeyName()] ?? $value;
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Adds one comparison predicate to `$query`. The three wildcard-`LIKE`
     * operators (`like`/`starts`/`ends`) compile to a `LOWER(col) LIKE ? ESCAPE '!'`
     * raw fragment with the value lower-cased and wildcard-escaped, mirroring the
     * in-memory `stripos`/`str_ends_with` family (a non-string value matches
     * nothing); every other operator maps to its SQL comparison with a bound value.
     *
     * @param Builder<Model> $query
     */
    private function applyComparison(Builder $query, string $column, string $operator, mixed $expected, string $key): void
    {
        if ($operator === 'like') {
            $this->likeMatch($query, $column, $expected, '%', '%');

            return;
        }
        if ($operator === 'starts') {
            $this->likeMatch($query, $column, $expected, '', '%');

            return;
        }
        if ($operator === 'ends') {
            $this->likeMatch($query, $column, $expected, '%', '');

            return;
        }

        $sqlOperator = match ($operator) {
            '=', '==', '===' => '=',
            '!=', '<>' => '<>',
            '>', '>=', '<', '<=' => $operator,
            default => throw new \LogicException(\sprintf(
                'Filter "%s" declares operator "%s", which has no SQL equivalent.',
                $key,
                $operator,
            )),
        };

        $this->compare($query, $column, $sqlOperator, $expected);
    }

    /**
     * Adds `column operator value` to `$query`. A date-time compared against a
     * datetime-cast column is first moved into the zone the column is stored in, and
     * one compared against a `date` cast compares its own calendar day with
     * `whereDate()`, so a midnight bound in another offset never shifts a day.
     *
     * @param Builder<Model> $query
     */
    private function compare(Builder $query, string $column, string $operator, mixed $value): void
    {
        $qualified = $this->qualify($query, $column);

        if ($value instanceof \DateTimeInterface) {
            [$model, $attribute] = $this->modelOf($query, $column);

            if ($this->dates->isDateOnly($model, $attribute)) {
                $query->whereDate($qualified, $operator, $value->format('Y-m-d'));

                return;
            }

            if ($this->dates->isNaive($model, $attribute)) {
                $value = $this->dates->toStorage($value);
            }
        }

        $query->where($qualified, $operator, $value);
    }

    /**
     * The model whose casts describe `$column`, and the column's name on it: the
     * pivot model for a `pivot.` column, the query's model otherwise.
     *
     * @param Builder<Model> $query
     *
     * @return array{Model, string}
     */
    private function modelOf(Builder $query, string $column): array
    {
        $column = $this->rowColumn($column);
        if ($this->pivot !== null && \str_starts_with($column, RelationCriteriaFactory::PIVOT_PREFIX)) {
            $pivotClass = $this->pivot->getPivotClass();

            return [new $pivotClass(), \substr($column, \strlen(RelationCriteriaFactory::PIVOT_PREFIX))];
        }

        return [$query->getModel(), $column];
    }

    /**
     * Wildcard-`LIKE` match, mirroring the in-memory `stripos` family byte-for-byte:
     * its ASCII case-insensitivity AND its LITERAL treatment of the search value.
     * Both sides are folded to lower case (`LOWER()` on the column, `strtolower()`
     * on the bound value) so the result does not depend on the platform's `LIKE`
     * collation (PostgreSQL's `LIKE` is case-sensitive; SQLite/MySQL fold ASCII
     * only — bundle R1's Postgres-case concern), and every `%`/`_` INSIDE the value
     * is escaped (with `!`, itself escaped first) under an explicit `ESCAPE '!'`
     * clause so it matches literally — exactly what `stripos`/`str_ends_with` do,
     * never a wildcard. A non-string value matches nothing (the in-memory comparison
     * needs two strings). `$prefix`/`$suffix` are the ONLY unescaped `%` wildcards,
     * wrapping the escaped value for the three strategies: contains (`%v%`, `like`),
     * starts-with (`v%`, `starts`) and ends-with (`%v`, `ends`).
     *
     * The column is server-declared, identifier-validated and table-qualified (never
     * client input) so interpolating it into the raw fragment is safe; the pattern is
     * always bound.
     *
     * @param Builder<Model> $query
     */
    private function likeMatch(Builder $query, string $column, mixed $expected, string $prefix, string $suffix): void
    {
        if (!\is_string($expected)) {
            // A literal fragment (no interpolation) — never matches.
            $query->whereRaw('1 = 0');

            return;
        }

        $pattern = $prefix . \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], \strtolower($expected)) . $suffix;

        // The fragment interpolates only the regex-validated, table-qualified column
        // (never client input); the value is bound. Laravel types whereRaw() $sql as a
        // `literal-string` to guard SQL injection — a false positive here (scoped ignore
        // in phpstan.neon.dist, mirroring the EloquentKeyset ORDER BY CASE fragment).
        $query->whereRaw(\sprintf("lower(%s) like ? escape '!'", $this->qualify($query, $column)), [$pattern]);
    }

    /**
     * Inclusive range predicate ({@see Range}/{@see DateRange}): each present,
     * non-blank bound is coerced through the filter's deserializer and bound on a
     * `>=`/`<=` predicate on the SAME query. A blank/absent bound (and a
     * {@see DateRange} bound that does not coerce to a `\DateTimeInterface`) is
     * treated as absent, byte-for-byte with the in-memory {@see bound()}.
     *
     * @param Builder<Model> $query
     */
    private function range(Builder $query, Range $filter, mixed $value): void
    {
        $bounds = \is_array($value) ? $value : [];
        $min = $this->bound($filter, $bounds, 'min');
        $max = $this->bound($filter, $bounds, 'max');

        if ($min !== null) {
            $this->compare($query, $filter->column, '>=', $min);
        }

        if ($max !== null) {
            $this->compare($query, $filter->column, '<=', $max);
        }
    }

    /**
     * Extracts and coerces one range bound: the deserialized bound value, or `null`
     * when the bound is absent or blank. A {@see DateRange} bound that does not
     * coerce to a `\DateTimeInterface` (a shape-valid but unparseable ISO-8601
     * string such as `1997-13-99`) is also treated as absent, so a non-date string
     * is never bound as a datetime parameter.
     *
     * @param array<array-key, mixed> $bounds
     */
    private function bound(Range $filter, array $bounds, string $key): mixed
    {
        if (!\array_key_exists($key, $bounds)) {
            return null;
        }

        /** @var mixed $value */
        $value = $bounds[$key];
        if ($value === null || $value === '') {
            return null;
        }

        $value = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        if ($filter instanceof DateRange && !$value instanceof \DateTimeInterface) {
            return null;
        }

        return $value;
    }

    /**
     * @param list<mixed>    $values
     * @param Builder<Model> $query
     */
    private function whereIn(Builder $query, string $column, array $values, bool $negate): void
    {
        if ($values === []) {
            // in_array(x, []) is always false: IN () matches nothing, NOT IN ()
            // matches everything (a no-op).
            if (!$negate) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $qualified = $this->qualify($query, $column);

        if ($negate) {
            $query->whereNotIn($qualified, $values);
        } else {
            $query->whereIn($qualified, $values);
        }
    }

    /**
     * A set filter's members: a declared list reads them through its {@see \haddowg\JsonApi\Resource\Filter\ListValue},
     * so an array-only list keeps a delimiter inside a member; any other set filter splits leniently.
     *
     * @return list<mixed>
     */
    private function members(WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter, mixed $value): array
    {
        return $filter->list?->members($value) ?? $this->toList($value, $filter->delimiter);
    }

    /**
     * Splits the request value the same way the in-memory handler does: arrays pass
     * through, strings split on the filter's delimiter (default `,`) with each
     * element trimmed, anything else becomes a single-element list.
     *
     * @return list<mixed>
     */
    private function toList(mixed $value, ?string $delimiter): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }

        if (\is_string($value)) {
            $separator = $delimiter !== null && $delimiter !== '' ? $delimiter : ',';

            return \array_values(\array_map('\trim', \explode($separator, $value)));
        }

        return [$value];
    }

    /**
     * The table-qualified column for a declared filter column, validated as an
     * identifier path (dots allowed for embedded fields) so a declaration typo fails
     * loudly rather than reaching the SQL parser interpolated, then qualified against
     * the query's model table so it survives joins.
     *
     * @param Builder<Model> $query
     */
    private function qualify(Builder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid column path.', $column));
        }

        $column = $this->rowColumn($column);
        if ($this->pivot !== null && \str_starts_with($column, RelationCriteriaFactory::PIVOT_PREFIX)) {
            return $this->pivot->getTable() . '.' . \substr($column, \strlen(RelationCriteriaFactory::PIVOT_PREFIX));
        }

        // An already-qualified column (a dotted table.column) is passed through by
        // qualifyColumn; a bare column is prefixed with the model table.
        return $query->getModel()->qualifyColumn($column);
    }

    /**
     * `$column` with its `pivot.` prefix stripped when it names a link row's own column.
     */
    private function rowColumn(string $column): string
    {
        return $this->rows && \str_starts_with($column, RelationCriteriaFactory::PIVOT_PREFIX)
            ? \substr($column, \strlen(RelationCriteriaFactory::PIVOT_PREFIX))
            : $column;
    }
}
