<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\DateTimeParser;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * The SQL a custom sort orders a cursor page by, returned from
 * {@see EloquentKeysetSortArmInterface::keysetExpression()}: an expression the
 * {@see EloquentKeyset} puts in both the forced NULL=largest `ORDER BY` and the
 * keyset `WHERE`, plus how a cursor's boundary value is turned back into a binding.
 *
 * The boundary value arrives in its token form: a scalar as written, a date-time as an
 * RFC 3339 string with microseconds. By default it binds as-is, which suits a number or
 * a string. {@see asDateTime()} parses it back to a date-time first, so the connection
 * formats it the way it stores one; {@see asNaiveDateTime()} also moves it into PHP's
 * default timezone, for an expression over a datetime column Eloquent stores without
 * its offset. Anything else goes through {@see castUsing()}.
 *
 * The expression is raw SQL. Build it from identifiers the server declares and pass
 * every value through `$bindings`, never the client's sort key.
 */
final readonly class EloquentKeysetExpression
{
    private const string AS_IS = 'as-is';

    private const string DATE_TIME = 'date-time';

    private const string NAIVE_DATE_TIME = 'naive-date-time';

    /**
     * @param list<mixed>                                   $bindings
     * @param (\Closure(string|int|float|bool): mixed)|null $cast
     */
    private function __construct(
        private Expression|string $sql,
        public array $bindings,
        private string $mode,
        private ?\Closure $cast,
    ) {}

    /**
     * @param Expression|string $sql      the expression, e.g. `CAST(sort_cap.value AS REAL)`
     * @param list<mixed>       $bindings the values for the expression's own `?` placeholders
     */
    public static function make(Expression|string $sql, array $bindings = []): self
    {
        return new self($sql, $bindings, self::AS_IS, null);
    }

    /**
     * Binds the boundary as a `\DateTimeImmutable` in the offset the token carries.
     */
    public function asDateTime(): self
    {
        return new self($this->sql, $this->bindings, self::DATE_TIME, null);
    }

    /**
     * Binds the boundary as a `\DateTimeImmutable` moved into PHP's default timezone,
     * the zone Eloquent writes a datetime-cast column in.
     */
    public function asNaiveDateTime(): self
    {
        return new self($this->sql, $this->bindings, self::NAIVE_DATE_TIME, null);
    }

    /**
     * Binds the boundary as whatever `$cast` returns for its token form.
     *
     * @param callable(string|int|float|bool): mixed $cast
     */
    public function castUsing(callable $cast): self
    {
        return new self($this->sql, $this->bindings, self::AS_IS, \Closure::fromCallable($cast));
    }

    /**
     * The expression's SQL for `$grammar`.
     */
    public function sql(Grammar $grammar): string
    {
        return \is_string($this->sql) ? $this->sql : (string) $this->sql->getValue($grammar);
    }

    /**
     * The binding for a non-null boundary value in its token form. A date-time string
     * that does not parse binds verbatim.
     */
    public function boundary(string|int|float|bool $value): mixed
    {
        if ($this->cast !== null) {
            return ($this->cast)($value);
        }

        if ($this->mode === self::AS_IS || !\is_string($value)) {
            return $value;
        }

        $parsed = DateTimeParser::tryParse($value);
        if ($parsed === null) {
            return $value;
        }

        return $this->mode === self::NAIVE_DATE_TIME ? (new NaiveDateTimeStorage())->toStorage($parsed) : $parsed;
    }
}
