<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A {@see EloquentSortArmInterface} whose sort can also order a cursor (keyset) page.
 *
 * Under a cursor the provider builds the order itself, so it never calls
 * {@see EloquentSortArmInterface::apply()}. It asks the arm for the expression the sort
 * orders by and uses it as it would a column: a NULL=largest `ORDER BY` and the null-aware
 * "strictly after the boundary" `WHERE`, comparing the expression with the value the
 * cursor token carries under the sort's key. That value is what the sort's
 * {@see KeysetSortInterface::keysetValue()} returned for the boundary row, so the
 * expression and `keysetValue()` must agree on every row, nulls included, unless the
 * expression reads its value from the query with
 * {@see EloquentKeysetExpression::valueFromQuery()}.
 *
 * The sort itself must implement {@see KeysetSortInterface}. A cursor-paged collection
 * declaring a sort that doesn't, or whose first supporting arm doesn't implement this
 * interface, fails `jsonapi:optimize`; one a sort family resolves per request is a `500`
 * {@see \haddowg\JsonApi\Resource\Sort\UnsupportedSort} under a cursor.
 *
 * @template TModel of Model
 *
 * @extends EloquentSortArmInterface<TModel>
 */
interface EloquentKeysetSortArmInterface extends EloquentSortArmInterface
{
    /**
     * The expression `$sort` orders `$query` by. Called once per query, so the arm may add
     * the joins the expression reads (a `leftJoin` keeps the rows that have no value, which
     * sort as null). When a join is added and the query selects nothing yet, the provider
     * selects the model's own columns so the joined ones don't overwrite them.
     *
     * @param Builder<TModel> $query
     */
    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression;
}
