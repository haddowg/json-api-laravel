<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Artist;

/**
 * The Eloquent provider's offset pagination arms executed against real SQLite: the
 * counted arm returns the grouped pre-window total, the count-free arm reports `hasMore`
 * from the N+1 probe (no COUNT), a null window fetches all, and a counted total reflects
 * the applied filter.
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
final class EloquentDataProviderPaginationTest extends EloquentTestCase
{
    #[Test]
    public function theCountedArmReturnsTheGroupedTotalAndThePage(): void
    {
        $this->seedArtists(5);

        $result = $this->fetch(new OffsetWindow(0, 2), wantsCount: true);

        self::assertSame(5, $result->total);
        self::assertTrue($result->windowed);
        self::assertSame(['Alpha', 'Bravo'], $this->names($result));
    }

    #[Test]
    public function theCountFreeArmProbesForMoreWithoutCounting(): void
    {
        $this->seedArtists(5);

        $result = $this->fetch(new OffsetWindow(0, 2), wantsCount: false);

        // No COUNT ran, so no total; hasMore comes from the limit+1 probe.
        self::assertNull($result->total);
        self::assertTrue($result->windowed);
        self::assertTrue($result->hasMore);
        self::assertSame(['Alpha', 'Bravo'], $this->names($result));
    }

    #[Test]
    public function theCountFreeArmReportsNoMoreOnAPartialLastPage(): void
    {
        $this->seedArtists(5);

        $result = $this->fetch(new OffsetWindow(4, 2), wantsCount: false);

        self::assertNull($result->total);
        self::assertFalse($result->hasMore);
        self::assertSame(['Echo'], $this->names($result));
    }

    #[Test]
    public function aNullWindowFetchesTheWholeCollection(): void
    {
        $this->seedArtists(3);

        $result = $this->fetch(null, wantsCount: false);

        self::assertNull($result->total);
        self::assertFalse($result->windowed);
        self::assertCount(3, $result->items);
    }

    #[Test]
    public function theCountedTotalReflectsTheAppliedFilter(): void
    {
        // Alpha, Alphabet, Bravo → `Contains 'alph'` matches the two "Alph…" rows.
        Artist::query()->create(['name' => 'Alpha', 'slug' => 'a', 'track_count' => 1]);
        Artist::query()->create(['name' => 'Alphabet', 'slug' => 'ab', 'track_count' => 1]);
        Artist::query()->create(['name' => 'Bravo', 'slug' => 'b', 'track_count' => 1]);

        $result = $this->fetch(
            new OffsetWindow(0, 1),
            wantsCount: true,
            filters: [Contains::make('nameContains', 'name')->build()],
            filter: ['nameContains' => 'alph'],
        );

        self::assertSame(2, $result->total);
        self::assertCount(1, $result->items);
    }

    private function seedArtists(int $count): void
    {
        $names = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
        for ($i = 0; $i < $count; $i++) {
            Artist::query()->create([
                'name' => $names[$i],
                'slug' => \strtolower($names[$i]),
                'track_count' => $i,
            ]);
        }
    }

    /**
     * @param list<FilterInterface> $filters
     * @param array<string, mixed>  $filter
     *
     * @return CollectionResult<object>
     */
    private function fetch(?OffsetWindow $window, bool $wantsCount, array $filters = [], array $filter = []): CollectionResult
    {
        $provider = new EloquentDataProvider(['artists' => Artist::class]);

        /** @var list<SortInterface> $sorts */
        $sorts = [SortByField::make('name')];

        return $provider->fetchCollection('artists', new CollectionCriteria(
            new QueryParameters([], [], ['name'], $filter, []),
            $filters,
            $sorts,
            $window,
            wantsCount: $wantsCount,
        ));
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function names(CollectionResult $result): array
    {
        $names = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Artist::class, $item);
            /** @var mixed $name */
            $name = $item->getAttribute('name');
            $names[] = (string) (\is_scalar($name) ? $name : '');
        }

        return $names;
    }
}
