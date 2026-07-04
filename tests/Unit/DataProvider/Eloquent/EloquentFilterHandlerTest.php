<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Numeric;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\StartsWith;
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
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;

/**
 * The Eloquent filter handler's SQL push-down: every core filter type is asserted
 * against the compiled SQLite SQL (`toSql()`) and its parameter bindings
 * (`getBindings()`), proving the `match(true)` maps each VO to the Builder calls the
 * blueprint's coverage table specifies — the Eloquent mirror of the in-memory witness.
 *
 * @internal
 */
#[CoversClass(EloquentFilterHandler::class)]
final class EloquentFilterHandlerTest extends EloquentTestCase
{
    private EloquentFilterHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EloquentFilterHandler();
    }

    #[Test]
    public function itQualifiesTheColumnAgainstTheModelTable(): void
    {
        [$sql, $bindings] = $this->apply(Where::make('status'), 'released');

        self::assertStringContainsString('where "artists"."status" = ?', $sql);
        self::assertSame(['released'], $bindings);
    }

    #[Test]
    public function itMapsComparisonOperators(): void
    {
        self::assertStringContainsString('"artists"."status" <> ?', $this->sql(Where::make('n', 'status', '!=')));
        self::assertStringContainsString('"artists"."track_count" >= ?', $this->sql(Where::make('n', 'track_count', '>=')));
        self::assertStringContainsString('"artists"."track_count" < ?', $this->sql(Where::make('n', 'track_count', '<')));
        // == / === both collapse to =.
        self::assertStringContainsString('"artists"."status" = ?', $this->sql(Where::make('n', 'status', '===')));
    }

    #[Test]
    public function itMapsContainsToACaseInsensitiveLike(): void
    {
        [$sql, $bindings] = $this->apply(Contains::make('nameContains', 'name'), 'port');

        // Blueprint R1: `lower(col) like ? escape '!'` with the value lower-cased and
        // wildcard-escaped — the case-fold does not depend on the driver's LIKE
        // collation and a literal `%`/`_` in the value stays literal (witness parity).
        // The column is table-qualified via qualifyColumn (as the keyset raw fragments
        // also interpolate it), regex-validated so raw interpolation is safe.
        self::assertStringContainsString('lower(artists.name) like ? escape \'!\'', $sql);
        self::assertSame(['%port%'], $bindings);
    }

    #[Test]
    public function itMapsStartsWithAndEndsWith(): void
    {
        self::assertSame(['port%'], $this->apply(StartsWith::make('n', 'name'), 'port')[1]);
        self::assertSame(['%head'], $this->apply(EndsWith::make('n', 'name'), 'head')[1]);
    }

    #[Test]
    public function itEscapesLikeWildcardsInTheSearchValue(): void
    {
        // A literal `%`/`_` (and the `!` escape char itself) in the search value is
        // escaped so it matches literally, mirroring the in-memory `stripos` (which
        // never treats them as wildcards). Only the strategy's wrapping `%` stay live.
        self::assertSame(['%100!% pure!_mix!!%'], $this->apply(Contains::make('n', 'name'), '100% Pure_Mix!')[1]);
    }

    #[Test]
    public function aNonStringLikeValueMatchesNothing(): void
    {
        [$sql, $bindings] = $this->apply(Contains::make('n', 'name'), ['not', 'a', 'string']);

        self::assertStringContainsString('1 = 0', $sql);
        self::assertSame([], $bindings);
    }

    #[Test]
    public function itCoercesNumericAndBooleanFilters(): void
    {
        // Numeric coercion: the wire string becomes a number before binding.
        self::assertSame([5], $this->apply(Numeric::make('n', 'track_count'), '5')[1]);
        self::assertSame([6], $this->apply(GreaterThan::make('n', 'track_count'), '6')[1]);
        // Boolean coercion: `1`/`true`/`on`/`yes` → true.
        self::assertSame([true], $this->apply(Boolean::make('explicit'), 'yes')[1]);
        self::assertSame([false], $this->apply(Boolean::make('explicit'), '0')[1]);
    }

    #[Test]
    public function itMapsWhereInAndItsEmptyContract(): void
    {
        [$sql, $bindings] = $this->apply(WhereIn::make('statusIn', 'status'), 'a,b');
        self::assertStringContainsString('"artists"."status" in (?, ?)', $sql);
        self::assertSame(['a', 'b'], $bindings);

        // Empty set → match nothing.
        self::assertStringContainsString('1 = 0', $this->sql(WhereIn::make('statusIn', 'status'), []));

        // Empty NOT IN → no-op (no predicate, no `1 = 0`).
        $noop = $this->sql(WhereNotIn::make('statusNotIn', 'status'), []);
        self::assertStringNotContainsString('1 = 0', $noop);
        self::assertStringNotContainsString('not in', $noop);
    }

    #[Test]
    public function itMapsIdSetFilters(): void
    {
        self::assertStringContainsString('"artists"."id" in (?, ?)', $this->sql(WhereIdIn::make(), '1,4'));
        self::assertStringContainsString('"artists"."id" not in (?)', $this->sql(WhereIdNotIn::make(), '1'));
    }

    #[Test]
    public function itMapsNullFilters(): void
    {
        self::assertStringContainsString('"artists"."website" is null', $this->sql(WhereNull::make('n', 'website'), '1'));
        self::assertStringContainsString('"artists"."website" is not null', $this->sql(WhereNotNull::make('n', 'website'), '1'));
    }

    #[Test]
    public function itMapsANumericRangeToTwoPredicates(): void
    {
        [$sql, $bindings] = $this->apply(Range::make('rating', 'track_count'), ['min' => '2', 'max' => '5']);

        self::assertStringContainsString('"artists"."track_count" >= ?', $sql);
        self::assertStringContainsString('"artists"."track_count" <= ?', $sql);
        self::assertSame([2, 5], $bindings);
    }

    #[Test]
    public function anAbsentOrBlankRangeBoundIsSkipped(): void
    {
        // Only min present → a single >= predicate.
        [$sqlMin, $bindMin] = $this->apply(Range::make('rating', 'track_count'), ['min' => '2']);
        self::assertStringContainsString('>= ?', $sqlMin);
        self::assertStringNotContainsString('<= ?', $sqlMin);
        self::assertSame([2], $bindMin);

        // A blank bound is a no-op (neither predicate).
        $noop = $this->sql(Range::make('rating', 'track_count'), ['min' => '', 'max' => '']);
        self::assertStringNotContainsString('>= ?', $noop);
        self::assertStringNotContainsString('<= ?', $noop);
    }

    #[Test]
    public function itSkipsACalendarInvalidDateRangeBound(): void
    {
        // A shape-valid but calendar-invalid bound does not coerce, so it is skipped
        // rather than bound as a raw string (which would compare lexically).
        $noop = $this->sql(DateRange::make('d', 'created_at'), ['min' => '1997-13-99']);

        self::assertStringNotContainsString('>= ?', $noop);
    }

    #[Test]
    public function itMapsRelationshipExistenceToAnExistsSubquery(): void
    {
        $has = $this->sql(WhereHas::make('hasAlbums', 'albums'), '1');
        self::assertStringContainsString('exists (select * from "albums"', $has);
        self::assertStringContainsString('"artists"."id" = "albums"."artist_id"', $has);

        $doesnt = $this->sql(WhereDoesntHave::make('noAlbums', 'albums'), '1');
        self::assertStringContainsString('not exists (select * from "albums"', $doesnt);
    }

    #[Test]
    public function itMapsAWhereThroughToANestedExistsWithALeafPredicate(): void
    {
        [$sql, $bindings] = $this->apply(WhereThrough::make('albumTitle', 'albums.title'), 'OK Computer');

        // A dotted path drives a nested whereHas whose closure compares the leaf on the
        // related (albums) table.
        self::assertStringContainsString('exists (select * from "albums"', $sql);
        self::assertStringContainsString('"albums"."title" = ?', $sql);
        self::assertSame(['OK Computer'], $bindings);
    }

    #[Test]
    public function itRaisesUnsupportedFilterForAnUnknownVoWithNoArm(): void
    {
        $this->expectException(UnsupportedFilter::class);

        $filter = new class implements FilterInterface {
            public function key(): string
            {
                return 'custom';
            }

            public function constraints(): array
            {
                return [];
            }
        };

        $this->handler->apply($filter, $this->newQuery(), 'x');
    }

    /**
     * Applies a filter to a fresh `artists` query and returns `[sql, bindings]`.
     *
     * @return array{string, list<mixed>}
     */
    private function apply(FilterInterface $filter, mixed $value): array
    {
        $query = $this->newQuery();
        $this->handler->apply($filter, $query, $value);

        return [$query->toSql(), \array_values($query->getBindings())];
    }

    private function sql(FilterInterface $filter, mixed $value = '1'): string
    {
        return $this->apply($filter, $value)[0];
    }

    /**
     * A fresh `artists` query typed as `Builder<Model>` — built through a
     * Model-typed class-string (as the provider does) so the invariant Builder
     * generic matches the handler's `FilterHandlerInterface<Builder<Model>>`
     * contract rather than narrowing to `Builder<Artist>`.
     *
     * @param class-string<Model> $class
     *
     * @return Builder<Model>
     */
    private function newQuery(string $class = Artist::class): Builder
    {
        return (new $class())->newQuery();
    }
}
