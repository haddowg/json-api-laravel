<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\Filter\DateOperand;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\MorphTarget;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\RelationHop;
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
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Filter\WhereSetMatch;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Through\FiltersThrough;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\ScopesFilterHopsInterface;
use haddowg\JsonApiLaravel\DataProvider\StoredIds;
use haddowg\JsonApiLaravel\DataProvider\TargetsTypeInterface;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

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
 * - Any filter declared with `->through()` is one nested `whereHas` per relation of
 *   its path, so the related model's global scopes apply at every hop, and the
 *   filter's own arm runs on the innermost query with the leaf core resolved. A
 *   relation that holds nothing gives the `EXISTS` no row, so it never matches.
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
final class EloquentFilterHandler implements FilterHandlerInterface, TargetsTypeInterface, ScopesFilterHopsInterface
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

    /**
     * Whether a bare column names a column of the join table `$pivot` joins, for the leaf of a
     * `->through()` path that ends on the pivot row of the relation it runs under.
     */
    private bool $onPivotRow = false;

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
     * The paths the filter being applied runs with, which name the relations it may match no
     * row through. Each `whereHas` hop applies the related model's global scopes itself.
     */
    private ?FilterPaths $paths = null;

    /**
     * How many admitted morph ids bind in one `IN` list, well under SQLite's 32766 and
     * PostgreSQL's 65535 parameters per statement.
     */
    private static int $morphIdChunk = 2000;


    /**
     * Whether the filter being applied is the leaf of a `->through()` path whose ids core has
     * decoded through the reached type's encoder already.
     */
    private bool $decodedLeaf = false;

    /**
     * @param iterable<EloquentFilterArmInterface<Model>>                                  $arms       author arms for custom filter types, consulted in order
     * @param ModelEncoders|null                                                     $encoders   picks the id encoder a compared key decodes with
     * @param (\Closure(string): ?AbstractResource)|null                             $resourceOf the resource serving a type, which says whether a relation reads through a foreign key
     */
    public function __construct(iterable $arms = [], private readonly ?ModelEncoders $encoders = null, private readonly ?\Closure $resourceOf = null)
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

    public function forPaths(FilterPaths $paths): static
    {
        $handler = clone $this;
        $handler->paths = $paths;

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

        if ($filter instanceof FiltersThrough && $filter->throughRelations() !== null) {
            $this->through($query, $filter, $value);

            return $query;
        }

        // A relationship filter reaching through a relation the caller may not read matches
        // as if every row there were hidden: an EXISTS through it is false.
        if ($this->paths !== null && ($filter instanceof TraversesPath || $filter instanceof WhereHas || $filter instanceof WhereDoesntHave || $filter instanceof WhereMorph) && $this->paths->refusesAnyOf($filter)) {
            if (!$filter instanceof WhereDoesntHave) {
                $query->whereRaw('1 = 0');
            }

            return $query;
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
            $filter instanceof WhereSet => $this->whereSet($query, $filter, $value),
            $filter instanceof WhereMorph => $this->whereMorph($query, $filter, $value),
            $filter instanceof WhereThrough => $this->whereThrough($query, $filter, $value),
            $filter instanceof WhereThroughIn => $this->whereThroughIn($query, $filter, $value),
            $filter instanceof WhereHas => $this->exists($query, $this->type, $filter->relationship, false),
            $filter instanceof WhereDoesntHave => $this->exists($query, $this->type, $filter->relationship, true),
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
     * A filter reaching through relations ({@see FiltersThrough}): one `whereHas` per relation
     * of its resolved path, each applying the related model's global scopes, with the filter's
     * own arm run on the innermost query against the leaf. A relation the filter may match no
     * row through admits none. An existence filter at the end of the path tests the relation
     * the reached row holds, as one more `whereHas` or `whereDoesntHave`.
     *
     * A path starting with `pivot` reads the pivot row the relation's query joined, so it runs
     * only on a pivot relation's query: ending there, the leaf compares the join table's
     * column, and going on, an `EXISTS` walks the pivot model's relations from that row. A
     * `pivot` anywhere else is {@see wherePivotRows()}.
     *
     * @param Builder<Model> $query
     */
    private function through(Builder $query, FiltersThrough $filter, mixed $value): void
    {
        $leaf = $filter->atLeaf();
        $path = $filter->resolvedThrough();
        \assert($path !== null);
        $leafValue = $filter->leafValue($value);
        $leafRelation = $path->leafRelation();

        $handler = clone $this;
        $handler->pivot = null;
        $handler->rows = false;
        $handler->onPivotRow = false;
        $handler->paths = null;
        $handler->type = $path->leafType();
        $handler->named = [$path->leafType()];
        $handler->decodedLeaf = $path->leafId()?->encoder() !== null;

        $exists = $leafRelation !== null && ($leaf instanceof WhereHas || $leaf instanceof WhereDoesntHave);
        $scope = fn(Builder $related) => $leafRelation !== null && $this->paths?->refuses($leafRelation) === true ? $related->whereRaw('1 = 0') : $related;
        $applyLeaf = static function (Builder $reached) use ($handler, $leaf, $leafValue, $exists, $scope): void {
            if ($exists) {
                \assert($leaf instanceof WhereHas || $leaf instanceof WhereDoesntHave);
                $handler->exists($reached, $handler->type, $leaf->relationship, $leaf instanceof WhereDoesntHave, $scope);

                return;
            }

            $handler->apply($leaf, $reached, $leafValue);
        };

        $hops = $path->hops;
        if (!$path->startsWithPivot()) {
            $this->whereHasAlong($query, $hops, $applyLeaf);

            return;
        }

        if ($this->pivot === null) {
            throw new \LogicException(\sprintf('Filter "%s" reaches through "%s", which starts at the pivot row of the relation it runs under, but it runs on a query that joins no pivot row.', $filter->key(), $path->path));
        }
        $under = \array_shift($hops);
        if ($this->paths?->refuses($under->relation) === true) {
            $query->whereRaw('1 = 0');

            return;
        }

        $row = $this->pivot->newPivot();
        if ($hops === [] && !$exists) {
            $handler->pivot = $this->pivot;
            $handler->onPivotRow = true;
            $handler->apply($leaf, $query, $leafValue);

            return;
        }

        // From the joined row: the pivot model's relation, correlated to the join table the
        // query already holds, as Eloquent builds a `whereHas` from the pivot model.
        $first = $hops === [] ? $leafRelation : \array_shift($hops)->relation;
        \assert($first !== null);
        $method = $first->column() ?? $first->name();
        $relation = \method_exists($row, $method) ? $row->{$method}() : null;
        if (!$relation instanceof EloquentRelation) {
            throw new \LogicException(\sprintf('Filter "%s" reaches through "%s", but "%s" is not a relation of %s.', $filter->key(), $path->path, $method, $row::class));
        }
        /** @var Builder<Model> $rowQuery */
        $rowQuery = $row->newQueryWithoutRelationships();
        /** @var Builder<Model> $related */
        $related = $relation->getRelationExistenceQuery($relation->getRelated()->newQueryWithoutRelationships(), $rowQuery);

        if ($exists && $first === $leafRelation) {
            $scope($related);
            $query->addWhereExistsQuery($related->toBase(), 'and', $leaf instanceof WhereDoesntHave);

            return;
        }
        if ($this->paths?->refuses($first) === true) {
            $related->whereRaw('1 = 0');
        } elseif ($hops === []) {
            $applyLeaf($related);
        } else {
            $this->whereHasAlong($related, $hops, $applyLeaf);
        }

        $query->addWhereExistsQuery($related->toBase());
    }

    /**
     * A `whereHas` over the first of `$hops` whose query nests the rest, with `$leaf` applied
     * to the innermost. A `pivot` hop goes on from the pivot rows instead
     * ({@see wherePivotRows()}).
     *
     * @param Builder<Model>          $query
     * @param list<RelationHop>       $hops
     * @param \Closure(Builder<Model>): void $leaf
     */
    private function whereHasAlong(Builder $query, array $hops, \Closure $leaf): void
    {
        $hop = \array_shift($hops);
        \assert($hop !== null);

        if ($hop->pivot) {
            $this->wherePivotRows($query, $hop, $hops, $leaf);

            return;
        }

        $inner = function (Builder $related) use ($hop, $hops, $leaf): void {
            if ($this->paths?->refuses($hop->relation) === true) {
                $related->whereRaw('1 = 0');

                return;
            }

            $hops === [] ? $leaf($related) : $this->whereHasAlong($related, $hops, $leaf);
        };
        if (!$this->foreignKeyExists($query, $hop->relation, $hop->parentType, $inner, false)) {
            $query->whereHas($hop->storage(), $inner);
        }
    }

    /**
     * A `whereHas`, or with `$not` a `whereDoesntHave`, over the relation of `$type` stored as
     * `$relationship`, through its foreign key when it declares one.
     *
     * @param Builder<Model>                       $query
     * @param (\Closure(Builder<Model>): mixed)|null $inner
     */
    private function exists(Builder $query, ?string $type, string $relationship, bool $not, ?\Closure $inner = null): void
    {
        $relation = null;
        foreach (($type !== null && $this->resourceOf !== null ? ($this->resourceOf)($type)?->allFields() : null) ?? [] as $field) {
            if ($field instanceof RelationInterface && ($field->column() ?? $field->name()) === $relationship) {
                $relation = $field;

                break;
            }
        }
        if ($relation !== null && $type !== null && $this->foreignKeyExists($query, $relation, $type, $inner, $not)) {
            return;
        }

        $not ? $query->whereDoesntHave($relationship, $inner) : $query->whereHas($relationship, $inner);
    }

    /**
     * An `EXISTS`, or with `$not` a `NOT EXISTS`, over the rows of a relation of `$parentType`
     * read through a foreign key, under the related model's global scopes: each row whose
     * foreign key column holds the outer row's key and, for a `MorphTo` identified by columns,
     * whose type column names `$parentType`, so a row of another type with the same id never
     * matches. `$inner` narrows the rows further. False when the relation declares no foreign
     * key the handler can read.
     *
     * @param Builder<Model>                       $query
     * @param (\Closure(Builder<Model>): mixed)|null $inner
     */
    private function foreignKeyExists(Builder $query, RelationInterface $relation, string $parentType, ?\Closure $inner, bool $not): bool
    {
        $name = ForeignKey::nameOf($relation);
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($name === null || $relatedType === null || \count($relation->relatedTypes()) !== 1 || $this->resourceOf === null) {
            return false;
        }
        $resource = ($this->resourceOf)($relatedType);
        $class = $this->encoders?->modelFor($relatedType);
        $foreignKey = $resource !== null ? ForeignKey::in($name, $resource) : null;
        if ($foreignKey === null || $class === null) {
            return false;
        }

        $related = new $class();
        $morph = $foreignKey->morphColumns();
        $belongsTo = $morph === null && $foreignKey->isRelation() ? EloquentDataProvider::belongsToOf($related, $foreignKey->column) : null;
        $attribute = match (true) {
            $morph !== null => $morph->id,
            $foreignKey->isRelation() => $belongsTo?->getForeignKeyName(),
            default => $foreignKey->column,
        };
        if ($attribute === null) {
            return false;
        }

        /** @var Builder<Model> $rows */
        $rows = $related->newQuery();
        $rows->whereColumn($related->qualifyColumn($attribute), '=', $query->getModel()->getQualifiedKeyName());
        if ($morph !== null) {
            $rows->whereIn($related->qualifyColumn($morph->type), $morph->storedValues($parentType));
        } elseif ($belongsTo instanceof EloquentMorphTo) {
            $rows->where($related->qualifyColumn($belongsTo->getMorphType()), $query->getModel()->getMorphClass());
        }
        if ($inner !== null) {
            $inner($rows);
        }
        $query->addWhereExistsQuery($rows->toBase(), 'and', $not);

        return true;
    }

    /**
     * A `pivot` hop in the middle of a path: an `EXISTS` over the pivot rows of the
     * `belongsToMany` the hop names, each linking the current row to a member that the
     * related model's global scopes admit, so a link to a hidden member never matches. The
     * rest of the path goes on from the pivot row, through the pivot model's relations.
     *
     * @param Builder<Model>          $query
     * @param list<RelationHop>       $hops  the hops after this one
     * @param \Closure(Builder<Model>): void $leaf
     */
    private function wherePivotRows(Builder $query, RelationHop $hop, array $hops, \Closure $leaf): void
    {
        $model = $query->getModel();
        $method = $hop->storage();
        $relation = \method_exists($model, $method) ? $model->{$method}() : null;
        if (!$relation instanceof EloquentBelongsToMany || $relation instanceof EloquentMorphToMany) {
            throw new \LogicException(\sprintf('The path "%s" reaches the pivot rows of "%s", which is not a belongsToMany of %s.', $hop->path, $method, $model::class));
        }
        if ($this->paths?->refuses($hop->relation) === true) {
            $query->whereRaw('1 = 0');

            return;
        }

        /** @var Builder<Model> $rows */
        $rows = $relation->newPivot()->newQueryWithoutRelationships();
        $rows->whereColumn($relation->getQualifiedForeignPivotKeyName(), '=', $model->qualifyColumn($relation->getParentKeyName()));

        $related = $relation->getRelated();
        $members = $related->newQuery()->whereColumn($related->qualifyColumn($relation->getRelatedKeyName()), '=', $relation->getQualifiedRelatedPivotKeyName());
        $rows->addWhereExistsQuery($members->toBase());

        $hops === [] ? $leaf($rows) : $this->whereHasAlong($rows, $hops, $leaf);
        $query->addWhereExistsQuery($rows->toBase());
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
        if (!$this->decodedLeaf && $this->isKeyOf($query->getModel(), $filter->column)) {
            $expected = $this->storageKey($query->getModel(), $expected, $filter, $filter->column, $this->type);
            if ($this->compareKey($query, $filter->column, $filter->operator, $expected)) {
                return;
            }
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
                    $key = $this->storageKey($belongsTo->getRelated(), $expected, $filter, $filter->throughPath());
                    if (!$this->compareKey($sub, $belongsTo->getForeignKeyName(), $operator, $key)) {
                        $this->compare($sub, $belongsTo->getForeignKeyName(), $operator, $key);
                    }

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
                    $this->whereIn($sub, $belongsTo->getForeignKeyName(), StoredIdForms::forms(null, \array_map(fn(mixed $value): mixed => $this->storageKey($related, $value, $filter, $filter->throughPath()), $values)), false);

                    return;
                }

                $this->whereIn($sub, $leaf ?? $sub->getModel()->getKeyName(), $values, false);
            },
        );
    }

    /**
     * @param Builder<Model> $query
     */
    private function whereSet(Builder $query, WhereSet $filter, mixed $value): void
    {
        $column = $this->qualify($query, $filter->column);

        match ($filter->match($value)) {
            WhereSetMatch::Set => $query->whereNotNull($column),
            WhereSetMatch::NotSet => $query->whereNull($column),
            WhereSetMatch::Either => null,
            WhereSetMatch::Neither => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * A {@see WhereMorph} over a `MorphTo` compares the morph type and id columns of the
     * model's own `morphTo` relation of that name, its stored type the morph class of the
     * model serving the requested type, so a morph map is honoured; a relation the model
     * doesn't define falls back to the columns `identifiedByColumns()` names. Over a
     * `MorphToMany` it is one `EXISTS` over the link rows `identifiedByRows()` names. A
     * stored id may hold the target's storage key or its wire id, so requested ids match
     * either.
     *
     * @param Builder<Model> $query
     */
    private function whereMorph(Builder $query, WhereMorph $filter, mixed $value): void
    {
        $selection = $filter->selection($value);
        $target = $filter->resolvedTarget();
        $relation = $target?->relation;
        $model = $query->getModel();
        $forms = $selection->ids !== null && $target !== null ? $target->storedForms($selection->type, $selection->ids) : $selection->ids;

        if ($relation instanceof MorphTo) {
            $storage = $relation->column() ?? $relation->name();
            $eloquent = \method_exists($model, $storage) ? $model->{$storage}() : null;
            if ($eloquent instanceof EloquentMorphTo) {
                $class = $this->encoders?->modelFor($selection->type);
                $stored = $class !== null ? [(new $class())->getMorphClass()] : [$selection->type];
                $morph = new MorphColumnsOf($model, $model->qualifyColumn($eloquent->getMorphType()), $stored, $eloquent->getForeignKeyName(), $model->qualifyColumn($eloquent->getForeignKeyName()));
                $this->morphPredicate($query, $morph, $forms);
                $this->scopeMorphTarget($query, $morph, $target, $selection->type, $forms, $eloquent->getOwnerKeyName());

                return;
            }

            $columns = $relation->identifierColumns();
            if ($columns !== null) {
                $morph = new MorphColumnsOf($model, $this->qualify($query, $columns->type), $columns->storedValues($selection->type), $columns->id, $this->qualify($query, $columns->id));
                $this->morphPredicate($query, $morph, $forms);
                $this->scopeMorphTarget($query, $morph, $target, $selection->type, $forms, null);

                return;
            }
        }

        if ($relation instanceof MorphToMany && ($rows = $relation->linkRowFields()) !== null) {
            $class = $rows->through;
            \assert(\is_a($class, Model::class, true));
            $row = new $class();
            $owner = \method_exists($row, $rows->owner) ? $row->{$rows->owner}() : null;

            $query->whereExists(function (QueryBuilder $sub) use ($row, $owner, $rows, $model, $selection, $target, $forms): void {
                $sub->selectRaw('1')->from($row->getTable());
                if ($owner instanceof EloquentMorphTo) {
                    $sub->whereColumn($row->qualifyColumn($owner->getForeignKeyName()), $model->getQualifiedKeyName())
                        ->where($row->qualifyColumn($owner->getMorphType()), $model->getMorphClass());
                } elseif ($owner instanceof EloquentBelongsTo) {
                    $sub->whereColumn($row->qualifyColumn($owner->getForeignKeyName()), $model->qualifyColumn($owner->getOwnerKeyName()));
                } else {
                    $sub->whereColumn($row->qualifyColumn($rows->owner), $model->getQualifiedKeyName());
                }
                $morph = new MorphColumnsOf($row, $row->qualifyColumn($rows->type), $rows->storedValues($selection->type), $rows->id, $row->qualifyColumn($rows->id));
                $this->morphPredicate($sub, $morph, $forms);
                $this->scopeMorphTarget($sub, $morph, $target, $selection->type, $forms, null);
            });

            return;
        }

        throw new \LogicException(\sprintf(
            'WhereMorph filter "%s" compares the relation "%s", which the Eloquent provider cannot query: define it as a morphTo relation on %s, or declare the MorphTo with identifiedByColumns() or the MorphToMany with identifiedByRows().',
            $filter->key,
            $filter->relation,
            $model::class,
        ));
    }

    /**
     * Keeps the targets of `$type` a `WhereMorph` compares to those the model serving the
     * type admits under its global scopes, as a related read of it does, so a hidden target
     * never matches. A model with no global scope needs nothing. The target is matched on
     * `$ownerKey`, its primary key when null.
     *
     * The stored ids are admitted as a related read finds them, through the type's id
     * encoder or the target key's cast: the requested ids when the request names some,
     * otherwise the distinct ids of that type the column holds. Only a key with no
     * value-object cast and no encoder, of the same family as the morph column, both
     * integers or both text, is compared with a subquery of the admitted keys instead.
     *
     * @param Builder<Model>|QueryBuilder $query
     * @param list<mixed>|null            $forms the requested ids in both stored forms, `null` when the request names none
     */
    private function scopeMorphTarget(Builder|QueryBuilder $query, MorphColumnsOf $morph, ?MorphTarget $target, string $type, ?array $forms, ?string $ownerKey): void
    {
        $class = $this->encoders?->modelFor($type);
        if ($class === null) {
            return;
        }

        $targetModel = new $class();
        if ($targetModel->getGlobalScopes() === []) {
            return;
        }

        $key = $ownerKey ?? $targetModel->getKeyName();
        $encoder = ($target?->ids[$type] ?? null)?->encoder();
        $comparison = $target === null ? [$targetModel->qualifyColumn($key)] : ($forms === null ? $this->sqlComparison($morph, $targetModel, $key, $encoder) : null);
        if ($comparison !== null) {
            // A membership IN rather than a correlated EXISTS: the target may share the filtered
            // model's table, and its scopes qualify columns with the table name.
            $grammar = $targetModel->getConnection()->getQueryGrammar();
            $query->where(static function (Builder|QueryBuilder $match) use ($morph, $targetModel, $comparison, $key, $grammar): void {
                foreach ($comparison as $select) {
                    $scoped = $targetModel->newQuery()->select($targetModel->qualifyColumn($key))->toBase();
                    if ($select === $targetModel->qualifyColumn($key)) {
                        $match->orWhereIn($morph->idColumn, $scoped);

                        continue;
                    }
                    // The scoped query selects the key read through the expression instead.
                    $column = 'select ' . $grammar->wrap($targetModel->qualifyColumn($key));
                    $sql = $scoped->toSql();
                    $at = \strpos($sql, $column);
                    $sql = $at === 0 ? 'select ' . $select . \substr($sql, \strlen($column)) : $sql;
                    $match->whereRaw(\sprintf('%s in (%s)', $grammar->wrap($morph->idColumn), $sql), $scoped->getBindings(), 'or');
                }
            });

            return;
        }

        if ($target === null) {
            return;
        }
        $admitted = $this->comparableIds($morph, StoredIds::eitherCase($this->admittedStoredIds($targetModel, $key, $target, $type, $forms ?? $this->storedMorphIds($morph))));
        if ($admitted === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        // Bound in chunks, so a long list stays under the database's parameter limit.
        $chunks = \array_chunk($admitted, \max(1, self::$morphIdChunk));
        if (\count($chunks) === 1) {
            $query->whereIn($morph->idColumn, $chunks[0]);

            return;
        }
        $query->where(static function (Builder|QueryBuilder $match) use ($morph, $chunks): void {
            foreach ($chunks as $chunk) {
                $match->orWhereIn($morph->idColumn, $chunk);
            }
        });
    }

    /**
     * Forgets the id column types read from the schema, for a test that recreates a table with
     * other types.
     *
     * @internal
     */
    public static function forgetColumnTypes(): void
    {
        StoredKey::forgetColumnTypes();
    }

    /**
     * Sets how many admitted ids bind in one `IN` list, for a test to force several.
     *
     * @internal
     */
    public static function chunkMorphIdsBy(int $size): void
    {
        self::$morphIdChunk = \max(1, $size);
    }

    /**
     * What a stored morph id compares with in one SQL statement, each the qualified target
     * key or SQL reading it, the morph column matching any of them, or null when it can't
     * compare in SQL, so the stored ids are admitted and bound instead.
     *
     * A key with neither an encoder nor a value-object cast compares as it is when both
     * columns are of one family, both integers or both text. On PostgreSQL a text key is
     * read as text, which a native uuid column needs. A binary UUID key behind
     * {@see BinaryUuidEncoder} is read as its canonical lowercase text, and on a database
     * whose text column can hold raw bytes it is also compared as stored, so a column
     * holding the storage key still matches. Any other pair, such as text against an
     * integer, which PostgreSQL has no comparison for, can't.
     *
     * @return non-empty-list<string>|null
     */
    private function sqlComparison(MorphColumnsOf $morph, Model $targetModel, string $key, ?IdEncoderInterface $encoder): ?array
    {
        if (StoredKey::castsValueObject($targetModel, $key)) {
            return null;
        }

        $column = $targetModel->qualifyColumn($key);
        $grammar = $targetModel->getConnection()->getQueryGrammar();
        $driver = $targetModel->getConnection()->getDriverName();
        $family = StoredKey::columnFamily($morph->model, $morph->idAttribute, $morph->table());
        if ($encoder instanceof BinaryUuidEncoder) {
            $text = $family !== 'text' ? null : BinaryUuidText::sql($targetModel->getConnection(), $grammar->wrap($column));

            // The text is lowercase, so its uppercase form matches a UUID stored in capitals.
            return $text === null ? null : ($driver === 'pgsql' ? [$text, 'UPPER(' . $text . ')'] : [$text, 'UPPER(' . $text . ')', $column]);
        }
        if ($encoder !== null) {
            return null;
        }

        $keyInt = $targetModel->getKeyType() === 'int';
        if (($family === 'int') !== $keyInt) {
            return null;
        }
        // A native uuid column compares only with another.
        if ($family === 'uuid') {
            return StoredKey::columnFamily($targetModel, $key, $targetModel->getTable()) === 'uuid' ? [$column] : null;
        }

        $read = $driver === 'pgsql' && !$keyInt ? \sprintf('CAST(%s AS TEXT)', $grammar->wrap($column)) : $column;
        // A HasUuids key may be stored in either case, so a reference holding a UUID in the
        // other case matches its upper or lower form.
        if (isset(\class_uses_recursive($targetModel)[HasUuids::class])) {
            $wrapped = $read === $column ? $grammar->wrap($column) : $read;

            return [$read, \sprintf('UPPER(%s)', $wrapped), \sprintf('LOWER(%s)', $wrapped)];
        }

        return [$read];
    }

    /**
     * The stored ids among `$stored`, each a storage key or a wire id of `$type`, whose
     * target `$targetModel` admits under its global scopes: each read as its wire id, then
     * decoded through the type's id encoder or set through the key's cast, as a related
     * read finds the target, in one key query.
     *
     * @param list<mixed> $stored
     *
     * @return list<mixed>
     */
    private function admittedStoredIds(Model $targetModel, string $key, MorphTarget $target, string $type, array $stored): array
    {
        $encoder = ($target->ids[$type] ?? null)?->encoder();
        $byKey = [];
        foreach ($stored as $value) {
            if (!\is_string($value) && !\is_int($value)) {
                continue;
            }
            $wire = $target->wireId($type, $value);
            $decoded = $encoder !== null ? $encoder->decode($wire) : StoredKey::of($targetModel, $key, StoredIds::canonical($wire));
            if (\is_string($decoded) || \is_int($decoded)) {
                $byKey[StoredIds::canonical($decoded)][] = $value;
            }
        }
        if ($byKey === []) {
            return [];
        }

        $admitted = [];
        $keys = \array_map(static fn(int|string $decoded): string => (string) $decoded, \array_keys($byKey));
        // The target may keep a uuid-like key in either case.
        foreach ($targetModel->newQuery()->whereIn($targetModel->qualifyColumn($key), StoredIdForms::forms(null, $keys))->toBase()->pluck($targetModel->qualifyColumn($key)) as $found) {
            if (\is_string($found) || \is_int($found)) {
                \array_push($admitted, ...$byKey[StoredIds::canonical($found)] ?? []);
            }
        }

        return $admitted;
    }


    /**
     * The distinct ids of a type among the stored type values the morph columns hold,
     * unscoped: the targets a type-only `WhereMorph` can reach.
     *
     * @return list<string|int>
     */
    private function storedMorphIds(MorphColumnsOf $morph): array
    {
        if ($morph->storedTypes === []) {
            return [];
        }

        $ids = [];
        foreach ($morph->model->getConnection()->table($morph->table())->whereIn($morph->typeColumn, $morph->storedTypes)->distinct()->pluck($morph->idColumn) as $id) {
            if (\is_int($id) || (\is_string($id) && $id !== '')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The values among `$values` the morph id column can hold: a column the model casts to
     * an integer keeps only integers and digit strings, so a wire id another form of the
     * same key takes is never compared as a number it isn't.
     *
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function comparableIds(MorphColumnsOf $morph, array $values): array
    {
        return match (StoredKey::columnFamily($morph->model, $morph->idAttribute, $morph->table())) {
            'int' => \array_values(\array_filter($values, static fn(mixed $value): bool => \is_int($value) || (\is_string($value) && \preg_match('/^-?\d+$/', $value) === 1))),
            'uuid' => \array_values(\array_filter($values, static fn(mixed $value): bool => \is_string($value) && Str::isUuid($value))),
            default => \array_values($values),
        };
    }


    /**
     * The type column among the stored type values and, when the request names ids, the id
     * column among them.
     *
     * @param Builder<Model>|QueryBuilder $query
     * @param list<mixed>|null            $ids
     */
    private function morphPredicate(Builder|QueryBuilder $query, MorphColumnsOf $morph, ?array $ids): void
    {
        if ($morph->storedTypes === [] || $ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($morph->typeColumn, $morph->storedTypes);
        if ($ids === null) {
            return;
        }

        $ids = $this->comparableIds($morph, StoredIds::eitherCase($ids));
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereIn($morph->idColumn, $ids);
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
        $column = $belongsTo?->getForeignKeyName() ?? $model->getKeyName();
        $operator = \in_array($operator, ['!=', '<>'], true) ? '<>' : '=';
        $key = $this->storageKey($model, $value, $filter, $filter->path);
        if (!$this->compareKey($query, $column, $operator, $key)) {
            $this->compare($query, $column, $operator, $key);
        }
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
        $this->whereIn($query, $belongsTo?->getForeignKeyName() ?? $model->getKeyName(), StoredIdForms::forms(null, \array_map(fn(mixed $value): mixed => $this->storageKey($model, $value, $filter, $filter->path), $values)), false);
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
        if ($this->decodedLeaf || !$this->isKeyOf($query->getModel(), $filter->column)) {
            return $members;
        }

        $model = $query->getModel();

        // A uuid-like key may be stored in either case.
        return StoredIdForms::forms(null, \array_map(fn(mixed $member): mixed => $this->storageKey($model, $member, $filter, $filter->column, $this->type), $members));
    }

    /**
     * Compares a key column with a uuid-like `$key` in both cases a text column may keep it
     * in, as an `IN` or `NOT IN`. False, adding nothing, for any other key or operator.
     *
     * @param Builder<Model> $query
     */
    private function compareKey(Builder $query, string $column, string $operator, mixed $key): bool
    {
        $forms = StoredIdForms::forms(null, [$key]);
        if (\count($forms) < 2 || !\in_array($operator, ['=', '!=', '<>'], true)) {
            return false;
        }
        $this->whereIn($query, $column, $forms, $operator !== '=');

        return true;
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
     * target only a pivot model reaches, stores its key through its own value-object
     * cast when it declares one.
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

        return $encoder !== null ? $encoder->decode($value) ?? $value : StoredKey::of($model, $model->getKeyName(), $value);
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

        if (($sqlOperator === '=' || $sqlOperator === '<>') && \is_string($expected) && StoredIdForms::isUuidLike($expected)) {
            $forms = $this->caseForms($query, $column, [$expected]);
            if (\count($forms) > 1) {
                $this->whereIn($query, $column, $forms, $sqlOperator === '<>');

                return;
            }
        }

        $this->compare($query, $column, $sqlOperator, $expected);
    }

    /**
     * `$values` with each uuid-like string in both cases a text column may keep it in, so
     * an equality filter binds every form rather than lowering the column and an index
     * still serves the query. A column PostgreSQL keeps as a native uuid compares without
     * case already, so its values are left alone, and so is every other value.
     *
     * @param Builder<Model> $query
     * @param list<mixed>    $values
     *
     * @return list<mixed>
     */
    private function caseForms(Builder $query, string $column, array $values): array
    {
        if (\array_filter($values, static fn(mixed $value): bool => \is_string($value) && StoredIdForms::isUuidLike($value)) === []) {
            return $values;
        }
        [$model, $attribute, $table] = $this->columnOf($query, $column);
        if ($model->getConnection()->getDriverName() === 'pgsql' && StoredKey::columnFamily($model, $attribute, $table) === 'uuid') {
            return $values;
        }

        $forms = [];
        $seen = [];
        foreach ($values as $value) {
            foreach (\is_string($value) && StoredIdForms::isUuidLike($value) ? StoredIdForms::forms(null, [$value]) : [$value] as $form) {
                if (\is_string($form) && isset($seen[$form])) {
                    continue;
                }
                if (\is_string($form)) {
                    $seen[$form] = true;
                }
                $forms[] = $form;
            }
        }

        return $forms;
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

        // A date filter's time of day compares the column's time, whatever day it holds.
        if ($value instanceof DateOperand && $value->kind === 'time') {
            $query->whereTime($qualified, $operator, $value->format('H:i:s'));

            return;
        }

        if ($value instanceof \DateTimeInterface) {
            [$model, $attribute] = $this->modelOf($query, $column);

            if ($this->dates->isDateOnly($model, $attribute) || ($value instanceof DateOperand && $value->kind === 'date')) {
                $query->whereDate($qualified, $operator, $value->format('Y-m-d'));

                return;
            }

            if ($this->dates->isNaive($model, $attribute)) {
                $value = $this->dates->toStorage($value);
            }
        }

        // A value the column can't hold compares as no stored value does, where PostgreSQL
        // would refuse it.
        if ($this->storable($query, $column, [$value]) === []) {
            \in_array($operator, ['!=', '<>'], true) ? $query->whereNotNull($qualified) : $query->whereRaw('1 = 0');

            return;
        }

        $query->where($qualified, $operator, $value);
    }

    /**
     * The values among `$values` that `$column` can hold. On PostgreSQL an integer or uuid
     * column refuses a string of another kind rather than matching nothing, so such a string
     * is left out; other values and other databases keep every value.
     *
     * @param Builder<Model> $query
     * @param list<mixed>    $values
     *
     * @return list<mixed>
     */
    private function storable(Builder $query, string $column, array $values): array
    {
        [$model, $attribute, $table] = $this->columnOf($query, $column);
        if ($model->getConnection()->getDriverName() !== 'pgsql') {
            return $values;
        }

        return match (StoredKey::columnFamily($model, $attribute, $table)) {
            'int' => \array_values(\array_filter($values, static fn(mixed $value): bool => !\is_string($value) || \preg_match('/^-?\d+$/', $value) === 1)),
            'uuid' => \array_values(\array_filter($values, static fn(mixed $value): bool => !\is_string($value) || Str::isUuid($value))),
            default => $values,
        };
    }

    /**
     * The model whose casts describe `$column`, the column's name on it and the table that
     * holds it.
     *
     * @param Builder<Model> $query
     *
     * @return array{Model, string, string}
     */
    private function columnOf(Builder $query, string $column): array
    {
        [$model, $attribute] = $this->modelOf($query, $column);
        $table = $this->pivot !== null && ($this->onPivotRow || \str_starts_with($this->rowColumn($column), RelationCriteriaFactory::PIVOT_PREFIX)) ? $this->pivot->getTable() : $model->getTable();
        $dot = \strrpos($attribute, '.');
        if ($dot !== false) {
            $table = \substr($attribute, 0, $dot);
            $attribute = \substr($attribute, $dot + 1);
        }

        return [$model, $attribute, $table];
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
        if ($this->pivot !== null && $this->onPivotRow) {
            return [$this->pivot->newPivot(), $column];
        }
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

        // A date filter's members each compare as the date they are, as one value does.
        if (\array_filter($values, static fn(mixed $value): bool => $value instanceof DateOperand) !== []) {
            $any = function (Builder $group) use ($column, $values): void {
                foreach ($values as $value) {
                    $group->orWhere(function (Builder $one) use ($column, $value): void {
                        $this->compare($one, $column, '=', $value);
                    });
                }
            };
            $negate ? $query->whereNot($any) : $query->where($any);

            return;
        }

        $qualified = $this->qualify($query, $column);
        $values = $this->storable($query, $column, $this->caseForms($query, $column, \array_values($values)));
        if ($values === []) {
            if (!$negate) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($negate) {
            $query->whereNotIn($qualified, $values);
        } else {
            $query->whereIn($qualified, $values);
        }
    }

    /**
     * A set filter's members: a declared list reads them through its {@see \haddowg\JsonApi\Resource\Filter\ListValue},
     * so an array-only list keeps a delimiter inside a member; any other set filter splits leniently. A
     * `WhereIn` or `WhereNotIn` then maps them through its `deserializeUsing()`.
     *
     * @return list<mixed>
     */
    private function members(WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter, mixed $value): array
    {
        $members = $filter->list?->members($value) ?? $this->toList($value, $filter->delimiter);

        return $filter instanceof WhereIn || $filter instanceof WhereNotIn ? $filter->comparedValues($members) : $members;
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
        if ($this->pivot !== null && $this->onPivotRow) {
            return $this->pivot->getTable() . '.' . $column;
        }
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
