<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A **self-applying** Eloquent filter: it carries its own query fragment — a named
 * scope (`->active()`), a `where` closure, a relationship-existence clause — so it
 * needs **no** separate {@see EloquentFilterArmInterface} service to run.
 *
 * The self-applying twin of the arm seam: where an arm is a registered service keyed on
 * a filter's class, this puts the application on the filter value object itself. The
 * {@see EloquentFilterHandler} consults it **before** the arm registry (the built-ins
 * still win), so a one-off, dependency-free custom filter is fully defined by its own VO
 * — the execution counterpart of the {@see \haddowg\JsonApiLaravel\Validation\Constraint\LaravelRules}
 * carrier for validation. Reach for an arm instead when the application needs injected
 * services (a repository, an auth guard).
 *
 * Pair it with core's {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter} to
 * also document a non-scalar `filter[<key>]` parameter, and a filter becomes wholly
 * self-contained — value schema, OpenAPI shape, and execution — in one class.
 *
 * **Eloquent-only, and not portable.** It runs only on the Eloquent provider; the same
 * `filter[<key>]` key is undeclared on the in-memory provider, so a request there is a
 * clean `400` (the unrecognised-filter boundary) — never a silent non-match. A filter
 * that must run on both providers ships a portable {@see FilterInterface} plus an arm per
 * store instead.
 *
 * Always bind the request value as a query parameter — Eloquent's `where()`/`whereIn()`
 * do this for you; only ever interpolate a validated, server-declared column into a
 * `whereRaw()`, never the client value.
 *
 * @template TModel of Model
 */
interface AppliesToEloquentQueryBuilder extends FilterInterface
{
    /**
     * Applies this filter to `$query` against the request `$value` — typically one or
     * more `where` predicates, or a named scope.
     *
     * @param Builder<TModel> $query
     */
    public function applyToQueryBuilder(Builder $query, mixed $value): void;
}
