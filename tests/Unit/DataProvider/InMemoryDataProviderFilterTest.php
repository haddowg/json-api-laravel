<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Numeric;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\StartsWith;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Song;
use haddowg\JsonApiLaravel\Tests\Fixtures\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory witness's filter execution: every core filter type the reference
 * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler} supports, dispatched
 * through the shared {@see CriteriaApplier} the Eloquent provider will run too — so a spec
 * failure on one provider but not the other localizes to that provider's execution.
 *
 * @internal
 */
#[CoversClass(InMemoryDataProvider::class)]
#[CoversClass(CriteriaApplier::class)]
final class InMemoryDataProviderFilterTest extends TestCase
{
    #[Test]
    public function itMatchesAnExactComparison(): void
    {
        // `Where` with the default `=` operator narrows to equal values.
        self::assertSame([1, 3], $this->filter([Where::make('status')], ['status' => 'released']));
    }

    #[Test]
    public function itMatchesANotEqualComparison(): void
    {
        self::assertSame([2], $this->filter([Where::make('statusNot', 'status', '<>')], ['statusNot' => 'released']));
    }

    #[Test]
    public function itMatchesGreaterThanOrEqualAndLessThanOrEqualComparisons(): void
    {
        // An ordered comparison against a NULL column never matches (core ADR 0116: an
        // ordered `<`/`<=`/`>`/`>=` — and therefore a Range bound — where either operand
        // is null yields false, mirroring SQL three-valued logic rather than PHP's silent
        // coercion of null toward 0). Row 3's rating is null, so it is excluded from BOTH
        // the `>=` set (`null >= 9.0` is false) and the `<=` set (`null <= 5.5` is false):
        // the in-memory witness now CONVERGES with the Eloquent/SQL reference (this pins
        // the resolved side of the divergence docs/adr/0003 deferred to core).
        self::assertSame([1], $this->filter([Where::make('ratingGte', 'rating', '>=')], ['ratingGte' => 9.0]));
        self::assertSame([2], $this->filter([Where::make('ratingLte', 'rating', '<=')], ['ratingLte' => 5.5]));
    }

    #[Test]
    public function itMatchesContainsCaseInsensitively(): void
    {
        // The ASCII case-insensitivity parity probe (`LIKE '%…%'` = `stripos`).
        self::assertSame([1, 2], $this->filter([Contains::make('titleContains', 'title')], ['titleContains' => 'ARTICLE']));
    }

    #[Test]
    public function itMatchesStartsWithCaseInsensitively(): void
    {
        self::assertSame([2], $this->filter([StartsWith::make('titleStarts', 'title')], ['titleStarts' => 'ARTICLE']));
    }

    #[Test]
    public function itMatchesEndsWithCaseInsensitively(): void
    {
        self::assertSame([2], $this->filter([EndsWith::make('titleEnds', 'title')], ['titleEnds' => 'TWO']));
    }

    #[Test]
    public function itCoercesAndMatchesABooleanFilter(): void
    {
        self::assertSame([2], $this->filter([Boolean::make('explicit')], ['explicit' => '1']));
        self::assertSame([1, 3], $this->filter([Boolean::make('explicit')], ['explicit' => 'false']));
    }

    #[Test]
    public function itCoercesAndMatchesANumericFilter(): void
    {
        // The wire value is a string; the numeric preset coerces it so the compare is numeric.
        self::assertSame([2], $this->filter([Numeric::make('rating')], ['rating' => '5.5']));
    }

    #[Test]
    public function itCoercesAndMatchesAGreaterThanFilter(): void
    {
        self::assertSame([1], $this->filter([GreaterThan::make('ratingGt', 'rating')], ['ratingGt' => '6']));
    }

    #[Test]
    public function itMatchesAWhereInSet(): void
    {
        self::assertSame([2], $this->filter([WhereIn::make('statusIn', 'status')], ['statusIn' => 'draft']));
    }

    #[Test]
    public function anEmptyWhereInMatchesNothing(): void
    {
        // An empty candidate list keeps no row (the `1=0` contract).
        self::assertSame([], $this->filter([WhereIn::make('statusIn', 'status')], ['statusIn' => []]));
    }

    #[Test]
    public function anEmptyWhereNotInMatchesEverything(): void
    {
        // An empty exclusion list is a no-op (`NOT IN ()` keeps every row).
        self::assertSame([1, 2, 3], $this->filter([WhereNotIn::make('statusNotIn', 'status')], ['statusNotIn' => []]));
    }

    #[Test]
    public function itMatchesAnIdSet(): void
    {
        self::assertSame([1, 3], $this->filter([WhereIdIn::make()], ['id' => '1,3']));
    }

    #[Test]
    public function itMatchesTheComplementOfAnIdSet(): void
    {
        self::assertSame([2, 3], $this->filter([WhereIdNotIn::make()], ['id' => '1']));
    }

    #[Test]
    public function itMatchesNullAndNotNull(): void
    {
        self::assertSame([3], $this->filter([WhereNull::make('ratingNull', 'rating')], ['ratingNull' => '1']));
        self::assertSame([1, 2], $this->filter([WhereNotNull::make('ratingSet', 'rating')], ['ratingSet' => '1']));
    }

    #[Test]
    public function itMatchesANumericRange(): void
    {
        self::assertSame([1], $this->filter([Range::make('ratingRange', 'rating')], ['ratingRange' => ['min' => '6']]));
        self::assertSame([2], $this->filter([Range::make('ratingRange', 'rating')], ['ratingRange' => ['min' => '5', 'max' => '8']]));
    }

    #[Test]
    public function anAbsentRangeIsANoOp(): void
    {
        self::assertSame([1, 2, 3], $this->filter([Range::make('ratingRange', 'rating')], ['ratingRange' => []]));
    }

    #[Test]
    public function itMatchesADateRange(): void
    {
        self::assertSame([2], $this->filter([DateRange::make('releasedRange', 'releasedAt')], ['releasedRange' => ['min' => '1998-01-01']]));
    }

    #[Test]
    public function itSkipsACalendarInvalidDateRangeBound(): void
    {
        // A shape-valid but calendar-invalid bound does not coerce, so it is treated as
        // absent rather than compared as a raw string — leaving the range open (no-op here).
        self::assertSame([1, 2, 3], $this->filter([DateRange::make('releasedRange', 'releasedAt')], ['releasedRange' => ['min' => '1997-13-99']]));
    }

    #[Test]
    public function itMatchesRelationshipExistence(): void
    {
        self::assertSame([1, 2], $this->filter([WhereHas::make('hasTags', 'tags')], ['hasTags' => '1']));
        self::assertSame([3], $this->filter([WhereDoesntHave::make('noTags', 'tags')], ['noTags' => '1']));
    }

    #[Test]
    public function itMatchesATraversalFilter(): void
    {
        // WhereThrough walks the dotted path and matches if any reached leaf satisfies the operator.
        self::assertSame([2], $this->filter([WhereThrough::make('tagName', 'tags.name')], ['tagName' => 'pop']));
        self::assertSame([1, 2], $this->filter([WhereThrough::make('tagName', 'tags.name')], ['tagName' => 'rock']));
    }

    #[Test]
    public function whereAnyFansOneValueAcrossColumnsAsAMultiColumnSearch(): void
    {
        // filter[search]=<v> -> title LIKE '%v%' OR status LIKE '%v%': one value fanned
        // across two columns.
        $group = [WhereAny::make('search', Contains::make('title'), Contains::make('status'))];

        // "article" matches both titles (1,2), no status.
        self::assertSame([1, 2], $this->filter($group, ['search' => 'article']));
        // "draft" matches only song 2's status, no title.
        self::assertSame([2], $this->filter($group, ['search' => 'draft']));
        // "zed" matches only song 3's title.
        self::assertSame([3], $this->filter($group, ['search' => 'zed']));
    }

    #[Test]
    public function whereAllOfFixedChildrenIsACannedToggleThatIgnoresTheRequestValue(): void
    {
        // filter[hotHits] present -> status = 'released' AND rating > 8, via fixed
        // children; the request value is ignored. Released = {1,3}; rating > 8 = {1}
        // (song 3's rating is null, excluded under ADR 0116).
        $group = [WhereAll::make(
            'hotHits',
            Where::make('status')->fixed('released'),
            GreaterThan::make('rating')->fixed(8),
        )];

        self::assertSame([1], $this->filter($group, ['hotHits' => 'anything']));
        self::assertSame([1], $this->filter($group, ['hotHits' => '0']));
    }

    #[Test]
    public function nestedGroupEvaluatesAAndBOrC(): void
    {
        // filter[scoped]=<v> -> title LIKE '%v%' AND (status = 'draft' OR rating > 8).
        $group = [WhereAll::make(
            'scoped',
            Contains::make('title'),
            WhereAny::make(
                'inner',
                Where::make('status')->fixed('draft'),
                GreaterThan::make('rating')->fixed(8),
            ),
        )];

        // "article" -> titles {1,2}; inner = draft{2} OR rating>8{1} = {1,2} -> {1,2}.
        self::assertSame([1, 2], $this->filter($group, ['scoped' => 'article']));
        // "two" -> title {2}; song 2 is a draft -> admitted via the status branch.
        self::assertSame([2], $this->filter($group, ['scoped' => 'two']));
        // "the" -> title {1}; song 1 is released but rating 9 > 8 -> admitted via the rating branch.
        self::assertSame([1], $this->filter($group, ['scoped' => 'the']));
    }

    #[Test]
    public function fixedStandalonePinsTheValueAndIsNotAppliedOnOmission(): void
    {
        // filter[onlyReleased]=<anything> -> status = 'released' (songs 1,3); the sent
        // value is ignored.
        $fixed = [Where::make('onlyReleased', 'status')->fixed('released')];

        self::assertSame([1, 3], $this->filter($fixed, ['onlyReleased' => '1']));
        // Sending 'draft' does NOT filter for drafts — the fixed 'released' wins.
        self::assertSame([1, 3], $this->filter($fixed, ['onlyReleased' => 'draft']));
        // Omitting the key does NOT apply it (contrast ->default()): every song survives.
        self::assertSame([1, 2, 3], $this->filter($fixed, []));
    }

    #[Test]
    public function multipleFiltersCombineConjunctively(): void
    {
        $result = $this->filter(
            [Where::make('status'), GreaterThan::make('ratingGt', 'rating')],
            ['status' => 'released', 'ratingGt' => '6'],
        );

        self::assertSame([1], $result);
    }

    #[Test]
    public function itRejectsAnUndeclaredFilterKey(): void
    {
        $this->expectException(FilterParamUnrecognized::class);

        $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(filter: ['nope' => 'x']),
            filters: [Where::make('title')->build()],
        ));
    }

    /**
     * Runs the given declared filter vocabulary against the requested `filter[…]` map and
     * returns the surviving ids (in store order — no sort applied).
     *
     * @param list<FilterInterface|FilterBuilderInterface> $filters
     * @param array<string, mixed>                         $filter
     *
     * @return list<int>
     */
    private function filter(array $filters, array $filter): array
    {
        $built = \array_values(\array_map(
            static fn(FilterInterface|FilterBuilderInterface $f): FilterInterface => $f instanceof FilterBuilderInterface ? $f->build() : $f,
            $filters,
        ));

        $result = $this->songs()->fetchCollection('songs', new CollectionCriteria(
            $this->query(filter: $filter),
            filters: $built,
        ));

        return $this->ids($result);
    }

    private function songs(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('songs', [
            '1' => new Song(1, 'The Article', 'released', 9.0, false, new \DateTimeImmutable('1997-05-21T00:00:00+00:00'), [new Tag('rock')]),
            '2' => new Song(2, 'Article Two', 'draft', 5.5, true, new \DateTimeImmutable('2001-01-01T00:00:00+00:00'), [new Tag('pop'), new Tag('rock')]),
            '3' => new Song(3, 'Zed', 'released', null, false, null, []),
        ]);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function query(array $filter = []): QueryParameters
    {
        return new QueryParameters([], [], [], $filter, []);
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<int>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Song::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
