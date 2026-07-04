<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Executes a requested sort order against an Eloquent query {@see Builder}: the
 * directives arrive most significant first (one composite call, core ADR 0016) and
 * append as sequential `orderBy` terms, so the request's first `sort` field is the
 * primary key, as the spec requires. Any non-{@see SortByField} directive (a
 * computed/multi-column sort) has no generic SQL translation and raises
 * {@see UnsupportedSort} unless a registered {@see EloquentSortArmInterface} handles
 * it — the Eloquent half of the framework's extensible-handler seam.
 *
 * @implements SortHandlerInterface<Builder<Model>>
 */
final class EloquentSortHandler implements SortHandlerInterface
{
    /**
     * Data-layer-specific remediation appended to the core {@see UnsupportedSort}
     * message when a custom sort reaches this handler with no arm to run it.
     */
    private const string ARM_HINT = 'To run a custom sort on the Eloquent provider, register an EloquentSortArmInterface on the EloquentDataProvider (constructor $sortArms).';

    /**
     * @var list<EloquentSortArmInterface<Model>>
     */
    private readonly array $arms;

    /**
     * @param iterable<EloquentSortArmInterface<Model>> $arms author arms for custom sort types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
    }

    public function apply(array $sorts, mixed $query): mixed
    {
        if (!$query instanceof Builder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                Builder::class,
                \get_debug_type($query),
            ));
        }

        foreach ($sorts as $directive) {
            $sort = $directive->sort;
            if ($sort instanceof SortByField) {
                $query->orderBy($this->qualify($query, $sort->column), $directive->descending ? 'desc' : 'asc');

                continue;
            }

            $this->applyArm($sort, $query, $directive->descending);
        }

        return $query;
    }

    /**
     * Delegates a custom {@see SortInterface} to the first registered
     * {@see EloquentSortArmInterface} that supports it; {@see UnsupportedSort} when
     * none does.
     *
     * @param Builder<Model> $query
     */
    private function applyArm(SortInterface $sort, Builder $query, bool $descending): void
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                $arm->apply($sort, $query, $descending);

                return;
            }
        }

        throw new UnsupportedSort($sort, self::ARM_HINT);
    }

    /**
     * The table-qualified sort column, validated as an identifier path and qualified
     * against the query's model table.
     *
     * @param Builder<Model> $query
     */
    private function qualify(Builder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid column path.', $column));
        }

        return $query->getModel()->qualifyColumn($column);
    }
}
