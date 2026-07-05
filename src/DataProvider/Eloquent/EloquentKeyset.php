<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Pagination\CursorBoundary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the Eloquent push-down for a cursor (keyset) page — the forced
 * NULL=largest `ORDER BY` and the IS-NULL-branched lexicographic keyset `WHERE` —
 * matching the in-memory witness ({@see \haddowg\JsonApi\Collection\Keyset\InMemoryKeyset})
 * byte-for-byte (bundle ADR 0063).
 *
 * The order is forced as a PORTABLE NULL=largest (NOT `NULLS LAST`, which
 * MySQL/SQLite lack): each column emits a leading `CASE WHEN c IS NULL THEN 1 ELSE
 * 0 END` term then the column, both in the column's direction — so ascending puts
 * non-nulls (0) before nulls (1) and descending reverses, every engine ordering
 * the 0/1 identically. The keyset `WHERE` is the lexicographic indicator of
 * "strictly after the boundary under that order": an OR over levels, each level
 * pinning the higher-significance columns to the boundary (null-aware `IS NULL`
 * equality) and requiring column i to be strictly after on its own — the four
 * AFTER cases. The final (PK) level is the plain `id >/< :v` tiebreak (the PK is
 * never null), so two rows tied on every sort column are still totally ordered.
 *
 * A date/datetime boundary value arrives as its JSON wire form (an ISO-8601
 * string); binding it verbatim against a datetime column would compare lexically
 * (a `T`-separated ISO string against the connection's space-separated storage
 * format), diverging from the in-memory witness. So a boundary bound to a
 * cast-date column is coerced back to a `\DateTimeImmutable` first, letting
 * Eloquent's connection format it to the stored form — the Eloquent analogue of
 * Doctrine's DBAL-typed bind.
 *
 * @template TModel of Model
 */
final class EloquentKeyset
{
    /**
     * @param TModel $model the query's model (its table qualifies columns; its `$casts` flag date columns)
     */
    public function __construct(private readonly Model $model) {}

    /**
     * Emits the forced NULL=largest `ORDER BY` for `$columns` onto `$builder`.
     *
     * @param Builder<TModel>    $builder
     * @param list<KeysetColumn> $columns
     */
    public function orderBy(Builder $builder, array $columns): void
    {
        foreach ($columns as $column) {
            $direction = $column->descending ? 'DESC' : 'ASC';
            $path = $this->qualify($column->column);
            // Portable NULL=largest: the CASE term is built from a validated identifier
            // qualified off the model table (no client input).
            $builder->orderByRaw(\sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END %s', $path, $direction));
            $builder->orderBy($path, $column->descending ? 'desc' : 'asc');
        }
    }

    /**
     * Applies the keyset `WHERE` for "strictly after `$boundary` under the order of
     * `$columns`" onto `$builder`. Every level is the asc + null-boundary degenerate
     * (nothing is strictly after a maximal null) → matches nothing.
     *
     * @param Builder<TModel>    $builder
     * @param list<KeysetColumn> $columns
     */
    public function applyAfter(Builder $builder, CursorBoundary $boundary, array $columns): void
    {
        $levels = [];
        foreach ($columns as $level => $column) {
            // asc + null boundary: a null is the maximal asc element, so nothing is
            // strictly after it on this column alone — drop the whole level (an
            // equality-only branch would wrongly match the tied rows).
            if (!$column->descending && ($boundary->values[$column->column] ?? null) === null) {
                continue;
            }
            $levels[] = $level;
        }

        if ($levels === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $or) use ($levels, $columns, $boundary): void {
            foreach ($levels as $level) {
                $or->orWhere(function (Builder $and) use ($level, $columns, $boundary): void {
                    // The equality prefix: every higher-significance column equals the
                    // boundary (a null boundary value → IS NULL, never `= null`).
                    for ($i = 0; $i < $level; $i++) {
                        $this->equals($and, $columns[$i], $boundary->values[$columns[$i]->column] ?? null);
                    }

                    $this->after($and, $columns[$level], $boundary->values[$columns[$level]->column] ?? null);
                });
            }
        });
    }

    /**
     * The null-aware equality predicate for the keyset's EQ prefix: a null boundary
     * value is `IS NULL` (never `= :v`, which is UNKNOWN and would drop the row); a
     * non-null value binds a (date-coerced) parameter.
     *
     * @param Builder<TModel> $query
     */
    private function equals(Builder $query, KeysetColumn $column, mixed $value): void
    {
        $path = $this->qualify($column->column);
        if ($value === null) {
            $query->whereNull($path);

            return;
        }

        $query->where($path, '=', $this->bindValue($column->column, $value));
    }

    /**
     * The "strictly after the boundary on this column alone" predicate under the
     * forced NULL=largest order for the column's direction — the four AFTER cases
     * (the asc + null degenerate is dropped by {@see applyAfter()} before this runs):
     *   asc  + bound non-null:  (col > :v OR col IS NULL)  (nulls follow all non-nulls)
     *   desc + bound non-null:  col < :v                   (nulls are first in desc, already before)
     *   desc + bound null:      col IS NOT NULL            (after a leading null come all non-nulls)
     *
     * @param Builder<TModel> $query
     */
    private function after(Builder $query, KeysetColumn $column, mixed $value): void
    {
        $path = $this->qualify($column->column);

        if (!$column->descending) {
            // Non-nulls greater than the boundary, plus ALL nulls (nulls follow).
            $bound = $this->bindValue($column->column, $value);
            $query->where(function (Builder $after) use ($path, $bound): void {
                $after->where($path, '>', $bound)->orWhereNull($path);
            });

            return;
        }

        if ($value === null) {
            $query->whereNotNull($path);

            return;
        }

        $query->where($path, '<', $this->bindValue($column->column, $value));
    }

    /**
     * Coerces a boundary value for binding: a wire ISO-8601 string bound to a
     * cast-date column becomes a `\DateTimeImmutable` (so the comparison is
     * chronological, not lexical — Eloquent's connection formats it to the stored
     * form); every other value binds as-is. A string that does not parse as a date
     * binds verbatim (the lenient fallback).
     */
    private function bindValue(string $column, mixed $value): mixed
    {
        if (!\is_string($value) || !$this->isDateColumn($column)) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Whether `$column` carries a date/datetime cast on the model — its keyset
     * boundary must bind as a `\DateTimeInterface` rather than a raw wire string.
     * Resolved through Eloquent's own {@see Model::hasCast()} against the date-family
     * cast types (it strips any `:format` parameter via `getCastType()`, so
     * `datetime:Y-m-d` and `timestamp` are covered) rather than a substring match on
     * the raw cast string — so a custom cast CLASS whose name happens to contain
     * "date"/"time" (e.g. `App\Casts\TimeSlot`) is never mistaken for a date column.
     */
    private function isDateColumn(string $column): bool
    {
        return $this->model->hasCast($column, [
            'date',
            'datetime',
            'immutable_date',
            'immutable_datetime',
            'custom_datetime',
            'immutable_custom_datetime',
            'timestamp',
        ]);
    }

    private function qualify(string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid column path.', $column));
        }

        return $this->model->qualifyColumn($column);
    }
}
