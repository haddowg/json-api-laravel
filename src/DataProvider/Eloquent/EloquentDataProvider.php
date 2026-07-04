<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany as PivotRelation;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\Keyset\CursorTokenMinter;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetResolver;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;

/**
 * The reference Eloquent read provider (PLAN decision 2): the storage twin of the
 * in-memory witness, executing the SAME {@see CriteriaApplier} matching against a
 * real {@see Builder} so a spec test failing on one provider but not the other
 * localizes the bug to that provider's *execution*.
 *
 * One instance serves every Eloquent-mapped type — constructed with a
 * `type → model class-string` map (the analogue of the Doctrine provider's
 * `entityClassByType`) — so `TEntity` cannot narrow past `object`. It registers at
 * the lowest priority (`-128`) so an application provider at the default priority
 * shadows it for the types it serves.
 *
 * A collection fetch is one `Builder` pipeline: the shared {@see CriteriaApplier}
 * matches the requested `filter[…]`/`sort` parameters against the declared
 * vocabularies and pushes each down through the {@see EloquentFilterHandler} /
 * {@see EloquentSortHandler}; the window/count/count-free tail then runs through
 * the shared {@see WindowExecutor} over `offset`/`limit`/`count` closures — items
 * are never over-fetched (a countable page counts then fetches; a count-free page
 * probes `limit + 1`). A cursor (keyset) window is its own pushed-down execution
 * ({@see EloquentKeyset}: the forced NULL=largest `ORDER BY` + the keyset `WHERE`),
 * resolving its columns from the ONE {@see KeysetResolver} the in-memory witness
 * uses, so SQL vs PHP windowing cannot drift.
 *
 * **Relationship reads (Phase 3a).** The six relation seams push the SAME core matching
 * down to Eloquent's relation machinery (PLAN decision 8):
 *  - {@see fetchRelatedCollectionBatch()} — the `?include` fast path: Eloquent's own eager
 *    pipeline ({@see EloquentRelation::addEagerConstraints()} + `getEager()` + `match()`)
 *    over the whole page of parents in ONE query, then a wire-keyed read-back. The SAME
 *    path serves the monomorphic to-one arm (a `BelongsTo`'s built-in FK projection). A
 *    **windowed** batch is the Relationship Queries profile's SQL push-down (Phase 3b) —
 *    reaching this seam with a window throws (PLAN decision 9: no PHP-window fallback,
 *    ever);
 *  - {@see countRelated()} — `?withCount`: one grouped `withCount()` correlated-subquery
 *    count for the whole page, zero-filled for the empty parents;
 *  - {@see fetchRelatedCollection()} — the related endpoint: a parent-scoped relation query
 *    (monomorphic) filtered/sorted/windowed through the shared pipeline, or the over-parity
 *    PHP window for a heterogeneous polymorphic to-many;
 *  - {@see relatedToOneMatches()}/{@see relatedToOneMatchesBatch()} — the filtered to-one
 *    probe: a `WHERE id (IN) … AND <filters>` existence check;
 *  - {@see fetchRelationshipPivot()} — `belongsToMany` pivot READ off the loaded pivot
 *    accessor.
 *
 * The `setRelation` write-back the include batcher applies makes
 * {@see Model::relationLoaded()} true, which the {@see EloquentRelationshipLoadState}
 * reports so a preloaded lazy relation renders without a re-fetch (the load-state seam).
 *
 * **Wiring (Phase 1).** The `type → model` map is constructed by hand and registered
 * at `-128` (see the workbench provider). ADR 0002's zero-config promise — a `model:`
 * on `#[AsJsonApiResource]` accumulated into one auto-registered `-128` provider, the
 * Laravel twin of the bundle's `DoctrineEntityMapPass` — is **deferred to Phase 2**,
 * alongside the persister half that completes the reference pair (ADR 0002,
 * "Deferred: attribute-driven auto-registration").
 *
 * @extends AbstractDataProvider<object>
 */
final class EloquentDataProvider extends AbstractDataProvider
{
    private readonly CriteriaApplier $applier;

    private readonly WindowExecutor $windowExecutor;

    private readonly EloquentFilterHandler $filterHandler;

    private readonly EloquentSortHandler $sortHandler;

    /**
     * The core reference in-memory handlers, used ONLY for the over-parity polymorphic
     * to-many related endpoint (a heterogeneous morph set has no single scoped SQL query,
     * so it is read off the parent and filtered/sorted/windowed in PHP — the SAME
     * execution the in-memory witness runs, so the two stay byte-identical). Every
     * monomorphic path pushes down to SQL through the Eloquent handlers instead.
     */
    private readonly ArrayFilterHandler $arrayFilterHandler;

    private readonly ArraySortHandler $arraySortHandler;

    private readonly KeysetResolver $keysetResolver;

    private readonly CursorTokenMinter $minter;

    /**
     * @var array<string, class-string<Model>>
     */
    private readonly array $modelByType;

    /**
     * @param array<string, class-string<Model>>          $modelByType a `type → Eloquent model FQCN` map
     * @param iterable<EloquentFilterArmInterface<Model>> $filterArms  author arms for custom `FilterInterface` types
     * @param iterable<EloquentSortArmInterface<Model>>   $sortArms    author arms for custom `SortInterface` types
     */
    public function __construct(array $modelByType, iterable $filterArms = [], iterable $sortArms = [])
    {
        $this->modelByType = $modelByType;
        $this->applier = new CriteriaApplier();
        $this->windowExecutor = new WindowExecutor();
        $this->filterHandler = new EloquentFilterHandler($filterArms);
        $this->sortHandler = new EloquentSortHandler($sortArms);
        $this->arrayFilterHandler = new ArrayFilterHandler();
        $this->arraySortHandler = new ArraySortHandler();
        $this->keysetResolver = new KeysetResolver();
        $this->minter = new CursorTokenMinter(new CursorCodec());
    }

    public function supports(string $type): bool
    {
        return isset($this->modelByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->newQuery($type)->whereKey($id)->first();
    }

    /**
     * @return CollectionResult<object>
     */
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $builder = $this->newQuery($type);

        // A cursor (keyset) window is its own pushed-down execution (the keyset
        // WHERE + the forced NULL=largest ORDER BY); the OffsetWindow / null-window
        // path stays byte-identical below. The keyset still applies the FILTERS via
        // the shared applier (and validates `?sort` through the resolver) but builds
        // its OWN order, never the plain sort handler (bundle ADR 0063).
        if ($criteria->window instanceof CursorWindow) {
            return $this->runCursor($builder, $criteria, $criteria->window);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        // Count-free by default (G21): the executor counts the pre-window total and
        // fetches the windowed page only when the handler resolved a COUNT for this
        // fetch (the paginator's withCount() author opt-in, or ?withCount=_self_
        // under a countable() resource); otherwise it fetches count-free via the
        // window+1 probe (no COUNT) and reports `hasMore` (bundle ADR 0075).
        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => \array_values($builder->get()->all()),
            count: static fn(): int => (clone $builder)->reorder()->count(),
            page: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
            probe: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
        );
    }

    /**
     * The related-collection endpoint (`GET /{type}/{id}/{rel}`) for a to-many relation:
     *  - **monomorphic**: the relation's own Eloquent query — already scoped to the parent
     *    (`$parent->{$method}()` adds the FK/pivot constraint) — filtered/sorted through the
     *    shared {@see CriteriaApplier} and windowed through the shared {@see WindowExecutor}
     *    over `offset`/`limit`/`count` closures, byte-identical to the primary collection tail;
     *  - **polymorphic** (a heterogeneous morph set, no single scoped SQL query): the
     *    over-parity path — read the mixed set off the parent via the relation accessor and
     *    filter/sort/window it in PHP through core's reference in-memory handlers, the SAME
     *    execution the in-memory witness runs (so an unknown `filter`/`sort` still `400`s and
     *    the two providers stay byte-identical). Doctrine throws here; this supports it.
     *
     * A **cursor** (keyset) related page is not yet supported on either provider: the relation
     * paginator chain can resolve a {@see \haddowg\JsonApi\Pagination\CursorPaginator} declared
     * on the related resource, whose window reaches the shared {@see WindowExecutor} (offset-only)
     * and throws — a deliberate parity gap shared with the in-memory witness AND the bundle's
     * Doctrine provider, flagged for a shared cursor-over-relation capability (a keyset scoped to
     * the parent, refereed by the witness) rather than an Eloquent-only workaround (Phase 3b).
     *
     * @return CollectionResult<object>
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        $relationQuery = \count($relation->relatedTypes()) === 1 && $parent instanceof Model
            ? $this->relationQuery($parent, $relation)
            : null;

        // A heterogeneous polymorphic to-many (or a non-Eloquent/extracted monomorphic
        // relation) has no single scoped SQL query — read the mixed set off the parent and
        // window it in PHP, exactly as the in-memory witness does.
        if ($relationQuery === null) {
            return $this->windowInPhp($criteria, $parent, $relation, $request);
        }

        // Constrain the SELECT to the related table's own columns BEFORE applying criteria.
        // A joined relation (BelongsToMany / monomorphic MorphToMany / a `through`) carries
        // the pivot INNER JOIN on its base query but NOT the relation's own `related.*`
        // projection (Eloquent's BelongsToMany::get() adds that via shouldSelect()). A raw
        // `select *` across the join hydrates every pivot column onto the related model, and
        // a pivot column whose name collides with the related table's — a pivot `id` (the
        // standard `$table->id()`), or `withTimestamps()` on created_at/updated_at — silently
        // overwrites the model attribute (PDO assoc keeps the LAST duplicate), so the related
        // resource would render the PIVOT ROW's id as its JSON:API id. Qualifying to
        // `related.*` is exactly what Eloquent's own relation get() does; for a non-joined
        // relation (HasMany/BelongsTo) it is a harmless `table.*`.
        $builder = $relationQuery->getQuery()->select($relationQuery->getRelated()->qualifyColumn('*'));
        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        // Count-free by default (G21): count the pre-window total only when the handler
        // resolved a COUNT (the relation paginator's withCount(), or ?withCount=_self_ under
        // a countable() relation); otherwise probe window+1 and report `hasMore`.
        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => \array_values($builder->get()->all()),
            count: static fn(): int => (clone $builder)->reorder()->count(),
            page: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
            probe: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
        );
    }

    /**
     * The batched related fetch — the `?include` fast path (PLAN decision 8, the literal
     * "addEagerConstraints + getEager + dictionary matching"). It runs Eloquent's own eager
     * pipeline over the whole page of parents in ONE query per relation (no top-level
     * `with()` orchestrator), then reads each parent's matched relation back into a
     * wire-keyed {@see RelatedBatch}. The SAME path serves the monomorphic to-one arm:
     * Eloquent unifies eager loading across cardinalities, so a `BelongsTo`'s built-in FK
     * projection (`whereIn(ownerKey, keys)`) is just a different {@see EloquentRelation}
     * through the identical code.
     *
     * `match()` both partitions (the dictionary match) AND {@see Model::setRelation()}s each
     * parent, so the write-back that makes {@see Model::relationLoaded()} true (and the
     * load-state seam report loaded) happens here; the orchestrator's subsequent write-back
     * is then idempotent.
     *
     * **No PHP-window fallback (PLAN decision 9, ADR 0006).** A windowed multi-parent batch
     * is the Relationship Queries profile's SQL push-down ({@see fetchWindowedBatch()}): one
     * `groupLimit`/`ROW_NUMBER() OVER (PARTITION BY <parent FK> ORDER BY <relation order>, <pk>)`
     * query bounds each parent's partition to page 1, deterministic on ties through the
     * appended primary-key tiebreak that the in-memory witness's `withPkTiebreak` mirrors. A
     * case that cannot push down (a computed/`extractUsing` relation with no Eloquent method,
     * a polymorphic relation the batcher never windows) throws rather than windowing in PHP.
     *
     * A relation this provider cannot batch (a computed/`extractUsing` column with no
     * Eloquent relation method, a polymorphic relation the include orchestrator already
     * skips, or a page carrying no Eloquent models) returns an empty {@see RelatedBatch}, so
     * the caller's write-back is a no-op and the relation renders lazily.
     */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        if ($criteria->window !== null) {
            return $this->fetchWindowedBatch($parentType, $parents, $relation, $criteria, $request);
        }

        $models = $this->eloquentModels($parents);
        if ($models === []) {
            return new RelatedBatch([]);
        }

        $eager = $this->eagerRelation($models[0], $relation);
        if ($eager === null) {
            return new RelatedBatch([]);
        }

        $method = $this->relationMethod($relation);

        // Eloquent's own eager pipeline: one query for the whole page, then the ORM-correct
        // dictionary partition + setRelation per parent (Builder::eagerLoadRelation's shape).
        $eager->addEagerConstraints($models);
        $models = $eager->initRelation($models, $method);
        $eager->match($models, $eager->getEager(), $method);

        $results = [];
        foreach ($models as $model) {
            $related = $model->getRelation($method);

            if ($relation->isToMany()) {
                $items = $related instanceof EloquentCollection ? \array_values($related->all()) : [];
            } else {
                $items = $related instanceof Model ? [$related] : [];
            }

            $results[$this->wireId($model)] = new CollectionResult($items);
        }

        return new RelatedBatch($results);
    }

    /**
     * The windowed multi-parent relation batch — the Relationship Queries profile's SQL
     * push-down (PLAN decision 9, ADR 0006). It bounds each parent's related partition to page
     * 1 of the relation's order in ONE query via Eloquent's own group-limit
     * (`ROW_NUMBER() OVER (PARTITION BY <parent FK> ORDER BY <sort>, <pk>)`), then partitions
     * the bounded rows per parent through the eager pipeline's dictionary `match()`. The order
     * is the requested/default sort followed by the primary key ASC — the SAME final tiebreak
     * the in-memory witness's `withPkTiebreak` appends, so the two providers resolve a tie to
     * byte-identical pages (the ADR 0006 determinism referee). NULL ordering is the shared
     * applier's plain `ORDER BY`, which on SQLite (and MySQL) places NULLs first on ASC / last
     * on DESC — byte-identical to the witness's `<=>` comparator (ADR 0006 addendum).
     *
     * The window is applied through the relation's own `limit()`: a multi-parent eager relation
     * has a non-existing parent, so `HasOneOrMany::limit()` / `BelongsToMany::limit()` route to
     * `Builder::groupLimit($value, $existenceCompareKey)` — the qualified foreign key (or, for
     * a `belongsToMany`, the qualified foreign pivot key) becomes the `PARTITION BY` column. A
     * countable relation bounds to `limit` rows and takes the per-parent total from the SAME
     * grouped `COUNT` {@see countRelated()} builds (zero-filled); a count-free relation bounds
     * to `limit + 1` and reads the surplus row as the `hasMore` probe. The group-limit derived
     * table re-exposes every inner column via its outer `SELECT *`, so both `match()` (reading
     * the FK) and the `belongsToMany` pivot hydration survive the wrap.
     *
     * There is NO PHP-window fallback (PLAN decision 9): a relation with no Eloquent method (a
     * computed/`extractUsing` column) or a polymorphic relation (which the batcher never
     * windows) cannot push down and throws with a signposted message.
     *
     * @param list<object> $parents
     *
     * @return RelatedBatch
     */
    private function fetchWindowedBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        $window = $criteria->window;
        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(
                'The Eloquent windowed relation batch pushes down only an offset window (the '
                . 'Relationship Queries profile pins the included page to page 1); a cursor '
                . '(keyset) window over a batched relation is not supported on either provider '
                . '— a shared parent-scoped keyset capability, refereed by the witness, is the '
                . 'follow-up, never an Eloquent-only workaround.',
            );
        }

        // A polymorphic relation spans related types with no single scoped query — the window
        // batcher never routes one here (it skips relatedTypes > 1); guard defensively.
        if (\count($relation->relatedTypes()) !== 1) {
            throw new \LogicException(\sprintf(
                'The Eloquent windowed relation batch cannot push down the polymorphic relationship "%s": '
                . 'its members span types with no shared scoped query, and the Relationship Queries profile '
                . 'never windows a polymorphic to-many.',
                $relation->name(),
            ));
        }

        $models = $this->eloquentModels($parents);
        if ($models === []) {
            return new RelatedBatch([]);
        }

        $eager = $this->eagerRelation($models[0], $relation);
        if ($eager === null) {
            throw new \LogicException(\sprintf(
                'The Eloquent windowed relation batch cannot push down "%s": it resolves to no Eloquent '
                . 'relation method, so there is no query to window. PLAN decision 9 (ADR 0006) forbids a '
                . 'PHP-window fallback — supply a custom DataProvider or an Eloquent relation method.',
                $relation->name(),
            ));
        }

        $method = $this->relationMethod($relation);

        // Filters + sort push down through the shared applier onto the relation's own eager
        // query (the SAME handlers the related endpoint uses, so an unknown filter/sort key is
        // the endpoint's same 400). The relation's own get() adds the correct projection
        // (related.* plus, for a belongsToMany, the aliased pivot columns), so the SELECT is
        // left untouched here — overriding it would drop the FK/pivot the partition needs.
        $this->applier->apply($criteria, $eager->getQuery(), $this->filterHandler, $this->sortHandler);

        // The deterministic primary-key tiebreak, appended AFTER the relation order so ties
        // resolve by id ASC — byte-identical to the witness's `withPkTiebreak`, so the SQL
        // ROW_NUMBER order and the witness's PHP stable sort select the SAME members on a tie.
        $related = $eager->getRelated();
        $eager->getQuery()->orderBy($related->qualifyColumn($related->getKeyName()), 'asc');

        $countable = $criteria->wantsCount;
        $limit = $window->limit;

        // A real offset (a non-include caller) rides the group-limit's own `laravel_row >
        // offset` bound; the profile pins page 1, so offset is 0 in practice.
        if ($window->offset > 0) {
            $eager->getQuery()->offset($window->offset);
        }

        // The window as a group-limit: a multi-parent eager relation's parent does not exist,
        // so limit() routes to groupLimit(PARTITION BY <existence compare key>). A count-free
        // relation probes one extra row per partition for the hasMore signal.
        $eager->limit($countable ? $limit : $limit + 1);

        // Eloquent's own eager pipeline: ONE group-limit query for the whole page, then the
        // ORM-correct dictionary partition — run over lightweight CLONES of the parents, NOT the
        // real ones. A clone keeps the key attributes `match()` reads, so the partition is
        // identical, but the windowed page (including the count-free `limit + 1` probe row) is
        // `setRelation()`'d onto the clones' relation cache — never the caller's models. Writing
        // it back onto the real parents (`initRelation`/`match` on `$models`) would flip their
        // `relationLoaded()` true and leave the shared relation cache holding the trimmed probe
        // page, corrupting a column-sharing sibling relation and contradicting the out-of-band
        // contract the batcher + WindowedRelationshipLinkage stake (ADR 0006). The page reaches
        // the wire through the relationship-linkage seam; the parents stay untouched.
        $clones = \array_map(static fn(Model $model): Model => clone $model, $models);
        $eager->addEagerConstraints($clones);
        $clones = $eager->initRelation($clones, $method);
        $eager->match($clones, $eager->getEager(), $method);

        // A countable relation's per-parent total is the SAME grouped, filtered COUNT the
        // ?withCount path builds (zero-filled for an empty partition).
        $totals = $countable
            ? $this->countRelated($parentType, $parents, $relation, $criteria, $request)
            : [];

        $results = [];
        foreach ($clones as $model) {
            $matched = $model->getRelation($method);
            $items = $matched instanceof EloquentCollection ? \array_values($matched->all()) : [];
            $wireId = $this->wireId($model);

            if ($countable) {
                // The group-limit already bounded the partition to `limit` rows.
                $results[$wireId] = new CollectionResult(
                    \array_slice($items, 0, $limit),
                    total: $totals[$wireId] ?? 0,
                    windowed: true,
                );

                continue;
            }

            // Count-free: the (limit + 1)-th row past the window proves a further page — drop it
            // and report hasMore.
            $hasMore = \count($items) > $limit;

            $results[$wireId] = new CollectionResult(
                $hasMore ? \array_slice($items, 0, $limit) : $items,
                total: null,
                windowed: true,
                hasMore: $hasMore,
            );
        }

        return new RelatedBatch($results);
    }

    /**
     * `?withCount`: ONE grouped, pushed-down count for the whole page of parents (PLAN
     * decision 8, `loadCount`-shaped). Eloquent's `withCount()` compiles a correlated
     * `COUNT` subquery column per relation, so a single `SELECT` over the parents yields
     * every parent's cardinality — `0` for the empty ones (auto zero-fill; every queried
     * parent yields a row). The relation's `relatedQuery[<rel>][filter]` pushes into the
     * subquery through the shared applier, so a `?withCount`-named relation that also
     * carries a filter counts the SAME set the related endpoint would page.
     *
     * Keyed by each parent's primary key as a string (the wire id the serializer's `getId()`
     * reports for the standard resource), so the count batcher reconciles each count back to
     * its parent. A relation with no Eloquent method, or a page with no models, reports the
     * empty map (the caller then supplies no count).
     *
     * @return array<int|string, int>
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        $models = $this->eloquentModels($parents);
        if ($models === []) {
            return [];
        }

        // Reuse the eager-relation guard (as the batch / to-one-match seams do): a name with
        // no Eloquent method — OR an existing method that does not return an Eloquent Relation
        // (e.g. a plain helper colliding with a countable relation's name) — reports the empty
        // map rather than letting withCount() throw a 500, keeping countRelated's degradation
        // contract aligned with the other seams.
        if ($this->eagerRelation($models[0], $relation) === null) {
            return [];
        }

        $method = $this->relationMethod($relation);
        $countAttribute = $method . '_count';
        $keys = \array_map(static fn(Model $model): mixed => $model->getKey(), $models);

        // Push the relation's relatedQuery filters into the correlated COUNT subquery via
        // the shared applier — the subquery Builder is filtered exactly as the related
        // endpoint filters its page. An empty criteria leaves the count as raw membership.
        //
        // Request the count column under an EXPLICIT alias (`<method> as <method>_count`)
        // rather than letting withCount() derive it: Eloquent names the default alias
        // `Str::snake("$name count")`, so a camelCase relation method (`blogPosts`) yields
        // `blog_posts_count`, which `getAttribute("blogPosts_count")` would miss (null →
        // coerced to 0) — a silent 0 on every multi-word relation. The alias pins the column
        // to exactly the attribute read below.
        $counted = $models[0]->newQuery()
            ->whereKey($keys)
            ->withCount([$method . ' as ' . $countAttribute => function (Builder $subQuery) use ($criteria): void {
                $this->applyCountCriteria($subQuery, $criteria);
            }])
            ->get();

        $counts = [];
        foreach ($counted as $model) {
            /** @var mixed $count */
            $count = $model->getAttribute($countAttribute);
            $counts[$this->wireId($model)] = \is_numeric($count) ? (int) $count : 0;
        }

        // Zero-fill any parent the query somehow did not return (belt and braces — whereKey
        // returns every loaded parent, so withCount already zero-fills the empty ones).
        foreach ($models as $model) {
            $counts[$this->wireId($model)] ??= 0;
        }

        return $counts;
    }

    /**
     * Whether the single related object of a filtered monomorphic to-one survives the
     * criteria's filters — a `SELECT 1 … WHERE id = ? AND <filters>` existence probe pushed
     * down to SQL. When it returns `false` the handler nulls the to-one (renders
     * `data: null`); `true` renders it unchanged. A non-model target cannot be probed, so it
     * is treated as matching (never silently dropped).
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        if (!$related instanceof Model) {
            return true;
        }

        $query = $this->applier->apply(
            $this->filtersOnly($criteria),
            $related->newQuery()->whereKey($related->getKey()),
            $this->filterHandler,
            $this->sortHandler,
        );

        return $query->exists();
    }

    /**
     * The batched to-one match over a page of parents — the include/primary path of the
     * `relatedQuery[<toOneRel>][filter]` profile (Phase 3b), run without N+1: Eloquent's
     * eager pipeline loads every parent's to-one target in ONE query, then a single
     * `WHERE id IN (…) AND <filters>` intersects the distinct targets against the filter. A
     * parent whose target is null, or whose target falls outside the matching set, maps to
     * `false`; keyed by each parent's primary key as a string. A relation with no Eloquent
     * method falls back to the neutral all-match default.
     *
     * @return array<string, bool>
     */
    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        $models = $this->eloquentModels($parents);
        if ($models === []) {
            return [];
        }

        $eager = $this->eagerRelation($models[0], $relation);
        if ($eager === null) {
            return parent::relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
        }

        $method = $this->relationMethod($relation);
        $eager->addEagerConstraints($models);
        $models = $eager->initRelation($models, $method);
        $eager->match($models, $eager->getEager(), $method);

        // Collect each parent's target key, and the distinct targets to probe.
        $parentTargetKey = [];
        $targets = [];
        foreach ($models as $model) {
            $target = $model->getRelation($method);
            $wireId = $this->wireId($model);

            if ($target instanceof Model) {
                $targetKey = $this->wireId($target);
                $parentTargetKey[$wireId] = $targetKey;
                $targets[$targetKey] = $target;
            } else {
                $parentTargetKey[$wireId] = null;
            }
        }

        // One filter probe over the distinct targets → the set of matching target keys.
        $matchingKeys = [];
        if ($targets !== []) {
            $sample = \array_values($targets)[0];
            $probe = $this->applier->apply(
                $this->filtersOnly($criteria),
                $sample->newQuery()->whereKey(\array_keys($targets)),
                $this->filterHandler,
                $this->sortHandler,
            );
            foreach ($probe->get() as $row) {
                $matchingKeys[$this->wireId($row)] = true;
            }
        }

        $matches = [];
        foreach ($parentTargetKey as $wireId => $targetKey) {
            $matches[$wireId] = $targetKey !== null && isset($matchingKeys[$targetKey]);
        }

        return $matches;
    }

    /**
     * The existing pivot meta for a `belongsToMany` relation's current members —
     * `relatedId => [pivotField => wire value]` — read straight off Eloquent's pivot
     * accessor (PLAN decision 8). It loads the relation's members (which hydrates the pivot
     * onto each), then reads each declared pivot field off the pivot model by its
     * `column ?? name`. The one seam the in-memory witness cannot model (it returns `[]`),
     * so a stored pivot row folds under an incoming member's linkage `meta` on the Eloquent
     * provider only.
     *
     * Returns `[]` for a non-pivot relation, a pivot relation that declares no pivot fields,
     * a relation with no Eloquent `belongsToMany` method, or a non-model parent — every
     * incoming member is then treated as new (create context).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        if (!$parent instanceof Model || !$relation instanceof PivotRelation) {
            return [];
        }

        $pivotFields = $relation->pivotFields();
        if ($pivotFields === []) {
            return [];
        }

        $method = $this->relationMethod($relation);
        if (!\method_exists($parent, $method)) {
            return [];
        }

        $eloquentRelation = $parent->{$method}();
        if (!$eloquentRelation instanceof EloquentBelongsToMany) {
            return [];
        }

        $accessor = $eloquentRelation->getPivotAccessor();

        $pivots = [];
        foreach ($eloquentRelation->get() as $member) {
            $pivot = $member->getRelation($accessor);
            if (!$pivot instanceof Model) {
                continue;
            }

            $meta = [];
            foreach ($pivotFields as $field) {
                $meta[$field->name()] = $pivot->getAttribute($field->column() ?? $field->name());
            }

            $pivots[$this->wireId($member)] = $meta;
        }

        return $pivots;
    }

    /**
     * The Eloquent relation method backing a JSON:API relation: its `column()` override, else
     * its name (blueprint §4). Default: the relationship name IS the Eloquent relation method.
     */
    private function relationMethod(RelationInterface $relation): string
    {
        return $relation->column() ?? $relation->name();
    }

    /**
     * A model's JSON:API wire id — its primary key coerced to a string (the value the
     * standard resource's serializer `getId()` reports, and the key every relation batch /
     * count / match map is keyed by). A non-scalar key (a value object) yields `''`.
     */
    private function wireId(Model $model): string
    {
        /** @var mixed $key */
        $key = $model->getKey();

        return \is_scalar($key) ? (string) $key : '';
    }

    /**
     * Pushes a criteria's filters onto a relation's correlated `withCount` COUNT subquery
     * (the {@see countRelated()} closure body), so the count reflects the SAME filtered set
     * the related endpoint would page. Extracted so the subquery Builder carries its element
     * type for the shared applier.
     *
     * @param Builder<Model> $subQuery
     */
    private function applyCountCriteria(Builder $subQuery, CollectionCriteria $criteria): void
    {
        $this->applier->apply($this->filtersOnly($criteria), $subQuery, $this->filterHandler, $this->sortHandler);
    }

    /**
     * The parent-scoped Eloquent relation query for a monomorphic relation, or `null` when
     * the parent carries no such relation method (a computed/`extractUsing` view). Resolving
     * `$parent->{$method}()` adds the constraint scoping the query to this parent.
     *
     * @return EloquentRelation<Model, Model, *>|null
     */
    private function relationQuery(Model $parent, RelationInterface $relation): ?EloquentRelation
    {
        $method = $this->relationMethod($relation);
        if (!\method_exists($parent, $method)) {
            return null;
        }

        $eloquentRelation = $parent->{$method}();

        return $eloquentRelation instanceof EloquentRelation ? $eloquentRelation : null;
    }

    /**
     * A constraint-free Eloquent relation for the eager pipeline (batch / to-one match): the
     * relation resolved under {@see EloquentRelation::noConstraints()} off a fresh instance
     * of the parent's model, so the single-parent constraint is NOT applied (the eager
     * `whereIn` over the whole page replaces it — Builder::getRelation's shape). Returns
     * `null` when the parent carries no such relation method.
     *
     * @return EloquentRelation<Model, Model, *>|null
     */
    private function eagerRelation(Model $parent, RelationInterface $relation): ?EloquentRelation
    {
        $method = $this->relationMethod($relation);
        if (!\method_exists($parent, $method)) {
            return null;
        }

        $prototype = $parent->newInstance();
        $eloquentRelation = EloquentRelation::noConstraints(static fn(): mixed => $prototype->{$method}());

        return $eloquentRelation instanceof EloquentRelation ? $eloquentRelation : null;
    }

    /**
     * The subset of `$parents` that are Eloquent models (the eager pipeline / count / pivot
     * seams operate only on models; a mixed graph's non-model members are skipped).
     *
     * @param list<object> $parents
     *
     * @return list<Model>
     */
    private function eloquentModels(array $parents): array
    {
        return \array_values(\array_filter($parents, static fn(object $parent): bool => $parent instanceof Model));
    }

    /**
     * The over-parity PHP window for a heterogeneous polymorphic to-many related endpoint:
     * read the mixed member set off the parent via the relation accessor, then filter / sort
     * / window it through core's reference in-memory handlers + the shared executor — the
     * SAME execution the in-memory witness runs, so an unknown `filter`/`sort` still `400`s
     * and the rendered page is byte-identical across the two providers.
     *
     * @return CollectionResult<object>
     */
    private function windowInPhp(
        CollectionCriteria $criteria,
        object $parent,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        $related = $relation->readValue($parent, $request);
        $items = match (true) {
            \is_array($related) => \array_values($related),
            $related instanceof \Traversable => \iterator_to_array($related, false),
            default => [],
        };

        /** @var list<object> $filtered */
        $filtered = $this->applier->apply($criteria, $items, $this->arrayFilterHandler, $this->arraySortHandler);

        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => $filtered,
            count: static fn(): int => \count($filtered),
            page: static fn(int $offset, int $limit): array => \array_slice($filtered, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($filtered, $offset, $limit),
        );
    }

    /**
     * The cursor (keyset) execution pushed down to SQL — the twin of the in-memory
     * witness ({@see \haddowg\JsonApiLaravel\DataProvider\Keyset\InMemoryKeyset}),
     * the ground truth (bundle ADR 0063). It resolves the keyset columns (the active
     * sort + the appended/deduped PK; validates `?sort`), applies the filters, checks
     * the cursor against the resolved columns (a stale cursor → 400), then via
     * {@see EloquentKeyset} builds the forced NULL=largest `ORDER BY` and the
     * IS-NULL-branched keyset `WHERE`, over-fetching `limit + 1` through the shared
     * {@see WindowExecutor::runCursor()}. A backward (`page[before]`) page flips every
     * direction and the after-predicate, then reverses the sliced rows to natural
     * forward order before minting.
     *
     * @param Builder<Model> $builder
     *
     * @return CursorCollectionResult<object>
     */
    private function runCursor(Builder $builder, CollectionCriteria $criteria, CursorWindow $window): CursorCollectionResult
    {
        $model = $builder->getModel();
        $pkColumn = $model->getKeyName();

        $columns = $this->keysetResolver->resolve($criteria, $pkColumn);

        // Apply the FILTERS only (the keyset owns the order). A sort-stripped,
        // window-less criteria reuses the shared applier so the filter semantics are
        // identical to a plain fetch, and the empty sort adds no ORDER BY.
        $builder = $this->applier->apply($this->filtersOnly($criteria), $builder, $this->filterHandler, $this->sortHandler);

        // page[before] wins over page[after]: a backward page flips the directions
        // (incl. the null bucket) and the after-predicate, so "after under the
        // reversed order" is "before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward ? $this->flip($columns) : $columns;

        if ($boundary !== null) {
            $parameter = $backward ? 'page[before]' : 'page[after]';
            $this->keysetResolver->assertFresh($boundary, $columns, $parameter);
        }

        $keyset = new EloquentKeyset($model);
        if ($boundary !== null) {
            $keyset->applyAfter($builder, $boundary, $orderColumns);
        }
        $keyset->orderBy($builder, $orderColumns);

        return $this->windowExecutor->runCursor(
            $window,
            // Over-fetch limit+1 in the (possibly flipped) order; the surplus is
            // dropped by runCursor BEFORE the cursors closure mints.
            probe: static fn(CursorWindow $w): array => \array_values((clone $builder)->limit($w->limit + 1)->get()->all()),
            cursors: function (array $rows, bool $hasMore) use ($window, $columns, $backward): CursorCollectionResult {
                // Re-orient a backward page to natural forward order for rendering.
                $page = $backward ? \array_reverse($rows) : $rows;

                return $this->minter->mint(
                    $window,
                    $columns,
                    \array_values($page),
                    $hasMore,
                    static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(
                        $row instanceof Model ? $row->getAttribute($column) : null,
                    ),
                );
            },
        );
    }

    /**
     * A sort-stripped, window-less copy of `$criteria` so the shared applier applies
     * only its FILTERS on the cursor path (the keyset owns the order).
     */
    private function filtersOnly(CollectionCriteria $criteria): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(
                $criteria->queryParameters->fields,
                $criteria->queryParameters->includes,
                sort: [],
                filter: $criteria->queryParameters->filter,
                pagination: $criteria->queryParameters->pagination,
            ),
            $criteria->filters,
            sorts: [],
            window: null,
            defaultSort: [],
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * The keyset columns with every direction flipped — the backward-page order
     * (which, under NULL=largest, also flips the null-bucket placement).
     *
     * @param list<KeysetColumn> $columns
     *
     * @return list<KeysetColumn>
     */
    private function flip(array $columns): array
    {
        return \array_map(
            static fn(KeysetColumn $column): KeysetColumn => new KeysetColumn($column->column, !$column->descending),
            $columns,
        );
    }

    /**
     * A fresh root query for the type's model.
     *
     * @return Builder<Model>
     */
    private function newQuery(string $type): Builder
    {
        $class = $this->modelByType[$type]
            ?? throw new \LogicException(\sprintf('No Eloquent model is mapped for JSON:API type "%s".', $type));

        $model = new $class();
        \assert($model instanceof Model);

        return $model->newQuery();
    }
}
