<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Sort\SortInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An extension "arm" for {@see EloquentSortHandler}: it appends the `ORDER BY` for
 * ONE custom {@see SortInterface} type to an Eloquent query {@see Builder}.
 * Implement it, register it on the {@see EloquentDataProvider} (constructor
 * `$sortArms`), and the provider consults every registered arm for any directive
 * whose sort is not a built-in {@see \haddowg\JsonApi\Resource\Sort\SortByField}
 * (first {@see supports()} match wins) before raising core's
 * {@see \haddowg\JsonApi\Resource\Sort\UnsupportedSort}.
 *
 * Directives arrive most-significant first and each arm appends its term in turn,
 * so a custom directive participates in the composite `ORDER BY` (primary,
 * secondary, or tie-breaker) exactly as a field sort does — `apply()` must
 * `orderBy`/`orderByRaw` (which append), never a call that discards the earlier
 * terms. This is the Eloquent twin of core's in-memory
 * {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface}; a portable
 * custom sort ships both.
 *
 * @template TModel of Model
 */
interface EloquentSortArmInterface
{
    /**
     * Whether this arm orders by `$sort`. Keyed on the sort's concrete type
     * (`$sort instanceof MySort`) — one arm backs one sort value-object class.
     */
    public function supports(SortInterface $sort): bool;

    /**
     * Appends the `ORDER BY` term for `$sort` to `$query` in the `$descending`
     * direction (via a call that keeps earlier directives, e.g. `orderBy`). Only
     * called when {@see supports()} returned `true`.
     *
     * @param Builder<TModel> $query
     */
    public function apply(SortInterface $sort, Builder $query, bool $descending): void;
}
