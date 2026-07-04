<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 * - {@see WhereHas}/{@see WhereDoesntHave} and {@see WhereThrough} are
 *   relationship-existence filters: Eloquent's `whereHas`/`whereDoesntHave`
 *   compile to a correlated `EXISTS`/`NOT EXISTS` subquery (set-membership, never
 *   a fetch-join, so the primary rows are neither duplicated nor need a
 *   `DISTINCT`), and a dotted `WhereThrough` path drives a nested `whereHas` whose
 *   leaf closure applies the comparison on the final relation.
 *
 * Columns come from the server-side resource declaration, never the client, and
 * are validated as identifier paths and qualified against the model's table before
 * being interpolated into a `LIKE`/raw fragment; values are always bound.
 *
 * A custom {@see FilterInterface} the built-ins do not recognise is delegated to a
 * registered {@see EloquentFilterArmInterface} (constructor-injected, first
 * {@see EloquentFilterArmInterface::supports()} match wins) before
 * {@see UnsupportedFilter} is raised — the Eloquent half of the framework's
 * extensible-handler seam.
 *
 * @implements FilterHandlerInterface<Builder<Model>>
 */
final class EloquentFilterHandler implements FilterHandlerInterface
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
     * @param iterable<EloquentFilterArmInterface<Model>> $arms author arms for custom filter types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
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
            $filter instanceof Where => $this->where($filter, $query, $value),
            $filter instanceof WhereIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereNotIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereIdIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereIdNotIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereNull => $query->whereNull($this->qualify($query, $filter->column)),
            $filter instanceof WhereNotNull => $query->whereNotNull($this->qualify($query, $filter->column)),
            $filter instanceof WhereThrough => $this->whereThrough($query, $filter, $value),
            $filter instanceof WhereHas => $query->whereHas($filter->relationship),
            $filter instanceof WhereDoesntHave => $query->whereDoesntHave($filter->relationship),
            // Range (and DateRange, which extends it): two push-down where predicates.
            $filter instanceof Range => $this->range($query, $filter, $value),
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

        $this->applyComparison($query, $filter->column, $filter->operator, $expected, $filter->key());
    }

    /**
     * The dotted-traversal existence filter ({@see WhereThrough}): an `EXISTS-ANY`
     * semi-join. Eloquent's `whereHas` accepts a dotted `a.b.c` relation string for
     * nested existence; the leaf attribute is split off the last segment and the
     * comparison applied in the closure on the final relation's query. Far simpler
     * than a hand-built subquery — the in-memory witness walks the object graph, this
     * compiles to a correlated EXISTS.
     *
     * @param Builder<Model> $query
     */
    private function whereThrough(Builder $query, WhereThrough $filter, mixed $value): void
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        $segments = \explode('.', $filter->path);
        if (\count($segments) < 2) {
            throw new \LogicException(\sprintf(
                'WhereThrough filter "%s" needs a dotted path "relationship.attribute"; got "%s".',
                $filter->key(),
                $filter->path,
            ));
        }

        $leaf = $segments[\count($segments) - 1];
        $relation = \implode('.', \array_slice($segments, 0, -1));

        $query->whereHas(
            $relation,
            function (Builder $sub) use ($leaf, $filter, $expected): void {
                $this->applyComparison($sub, $leaf, $filter->operator, $expected, $filter->key());
            },
        );
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

        $query->where($this->qualify($query, $column), $sqlOperator, $expected);
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
        $column = $this->qualify($query, $filter->column);
        $bounds = \is_array($value) ? $value : [];
        $min = $this->bound($filter, $bounds, 'min');
        $max = $this->bound($filter, $bounds, 'max');

        if ($min !== null) {
            $query->where($column, '>=', $min);
        }

        if ($max !== null) {
            $query->where($column, '<=', $max);
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
     * the query's model table so it survives the joins that arrive in later phases.
     *
     * @param Builder<Model> $query
     */
    private function qualify(Builder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid column path.', $column));
        }

        // An already-qualified column (a dotted table.column) is passed through by
        // qualifyColumn; a bare column is prefixed with the model table.
        return $query->getModel()->qualifyColumn($column);
    }
}
