<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An extension "arm" for {@see EloquentFilterHandler}: it pushes ONE custom
 * {@see FilterInterface} type down to an Eloquent query {@see Builder}. Implement
 * it, register it on the {@see EloquentDataProvider} (constructor `$filterArms`),
 * and the provider consults every registered arm for any filter its built-ins do
 * not recognise (first {@see supports()} match wins) before raising core's
 * {@see \haddowg\JsonApi\Resource\Filter\UnsupportedFilter}. The built-ins always
 * win — an arm is a fallthrough, never an override of `Where`/`WhereIn`/…
 *
 * This is the Eloquent twin of core's in-memory
 * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface}: a
 * **portable** custom filter ships both (the in-memory arm is the conformance
 * witness, this arm the production push-down) and the two stay behaviourally
 * identical under the shared conformance suite; an inherently Eloquent-specific
 * filter (a raw scope) ships only this one.
 *
 * Always bind the request value as a query parameter — Eloquent's `where()`/
 * `whereIn()` do this for you; only interpolate a validated, server-declared
 * column into a `whereRaw()` (never the client value).
 *
 * @template TModel of Model
 */
interface EloquentFilterArmInterface
{
    /**
     * Whether this arm executes `$filter`. Keyed on the filter's concrete type
     * (`$filter instanceof MyFilter`), not its key — one arm backs one filter
     * value-object class.
     */
    public function supports(FilterInterface $filter): bool;

    /**
     * Applies `$filter` to `$query` against the request `$value` (typically one or
     * more `where` predicates). Only called when {@see supports()} returned `true`.
     *
     * @param Builder<TModel> $query
     */
    public function apply(FilterInterface $filter, Builder $query, mixed $value): void;
}
