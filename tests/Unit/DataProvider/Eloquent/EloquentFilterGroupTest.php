<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;

/**
 * The Eloquent selection witness for the server-composed filter groups
 * ({@see WhereAll} / {@see WhereAny}) and the {@see Where::fixed()} wither (#24b,
 * core ADR 0129): the groups are compiled to SQL and EXECUTED against a seeded
 * SQLite `artists` table, asserting the surviving ids — the Eloquent half of the
 * dual-provider contract whose in-memory twin is
 * {@see \haddowg\JsonApiLaravel\Tests\Unit\DataProvider\InMemoryDataProviderFilterTest}.
 *
 * Seeds four artists: Radiohead (10 tracks, site radiohead.com), Portishead (3,
 * no site), Aphex Twin (8, site aphex.example), Boards of Canada (2, site
 * twinpeaks.example).
 *
 * @internal
 */
#[CoversClass(EloquentFilterHandler::class)]
final class EloquentFilterGroupTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artist::create(['name' => 'Radiohead', 'slug' => 'radiohead', 'website' => 'https://radiohead.com', 'track_count' => 10]);
        Artist::create(['name' => 'Portishead', 'slug' => 'portishead', 'website' => null, 'track_count' => 3]);
        Artist::create(['name' => 'Aphex Twin', 'slug' => 'aphex-twin', 'website' => 'https://aphex.example', 'track_count' => 8]);
        Artist::create(['name' => 'Boards of Canada', 'slug' => 'boc', 'website' => 'https://twinpeaks.example', 'track_count' => 2]);
    }

    #[Test]
    public function whereAnyFansOneValueAcrossColumnsAsAMultiColumnSearch(): void
    {
        // filter[q]=<v> -> name LIKE '%v%' OR website LIKE '%v%': one value fanned
        // across two columns as a compiled `(... or ...)`.
        $group = WhereAny::make('q', Contains::make('name'), Contains::make('website'));

        // "twin" matches Aphex Twin by name (3) and Boards of Canada by website (4) —
        // an OR union across BOTH columns and different rows.
        self::assertSame([3, 4], $this->matchedIds($group, 'twin'));
        // "head" matches Radiohead/Portishead by name and Radiohead by website -> {1,2}.
        self::assertSame([1, 2], $this->matchedIds($group, 'head'));
    }

    #[Test]
    public function whereAllOfFixedChildrenIsACannedToggleThatIgnoresTheRequestValue(): void
    {
        // filter[prolificRadio] present -> track_count > 5 AND name LIKE '%radio%',
        // via fixed children; the request value is ignored. tracks>5 = {1,3};
        // name~radio = {1} -> {1}.
        $group = WhereAll::make(
            'prolificRadio',
            GreaterThan::make('track_count')->fixed(5),
            Contains::make('name')->fixed('radio'),
        );

        self::assertSame([1], $this->matchedIds($group, 'anything'));
        self::assertSame([1], $this->matchedIds($group, '0'));
    }

    #[Test]
    public function nestedGroupEvaluatesAAndBOrC(): void
    {
        // filter[scoped]=<v> -> name LIKE '%v%' AND (website LIKE '%twin%' OR track_count > 5).
        $group = WhereAll::make(
            'scoped',
            Contains::make('name'),
            WhereAny::make(
                'inner',
                Contains::make('website')->fixed('twin'),
                GreaterThan::make('track_count')->fixed(5),
            ),
        );

        // "o" -> names {1,2,4}; inner = website~twin{4} OR tracks>5{1,3} = {1,3,4};
        // AND -> {1,4}. Boards of Canada(4) enters via the website branch, Radiohead(1)
        // via the tracks branch, Portishead(2) is gated out by the inner OR.
        self::assertSame([1, 4], $this->matchedIds($group, 'o'));
        // "twin" -> name {3}; Aphex Twin has 8 tracks > 5 -> admitted via the tracks branch.
        self::assertSame([3], $this->matchedIds($group, 'twin'));
        // "boards" -> name {4}; admitted via the website branch (twinpeaks).
        self::assertSame([4], $this->matchedIds($group, 'boards'));
    }

    #[Test]
    public function fixedStandalonePinsTheValueRegardlessOfWhatIsSent(): void
    {
        // filter[onlyRadio]=<anything> -> name LIKE '%radio%' (Radiohead only).
        $filter = Contains::make('onlyRadio', 'name')->fixed('radio');

        self::assertSame([1], $this->matchedIds($filter, 'anything'));
        // Sending a value that would otherwise match nothing is ignored — the fixed
        // 'radio' wins.
        self::assertSame([1], $this->matchedIds($filter, 'zzz'));
    }

    /**
     * Applies `$filter` with `$value` to a fresh `artists` query, executes it, and
     * returns the surviving ids ascending.
     *
     * @return list<int>
     */
    private function matchedIds(FilterInterface|FilterBuilderInterface $filter, mixed $value): array
    {
        // Build the query through a Model-typed class-string (as the provider does) so
        // it matches the handler's FilterHandlerInterface<Builder<Model>> contract
        // rather than narrowing to Builder<Artist>.
        /** @var class-string<Model> $class */
        $class = Artist::class;
        $query = (new $class())->newQuery();
        (new EloquentFilterHandler())->apply($filter instanceof FilterBuilderInterface ? $filter->build() : $filter, $query, $value);

        $ids = [];
        foreach ($query->orderBy('id')->get() as $model) {
            $key = $model->getKey();
            $ids[] = \is_numeric($key) ? (int) $key : 0;
        }

        return $ids;
    }
}
