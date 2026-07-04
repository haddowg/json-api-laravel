<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Widget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryDataProvider::class)]
final class InMemoryDataProviderTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyItsOwnType(): void
    {
        $provider = new InMemoryDataProvider('widgets', []);

        self::assertTrue($provider->supports('widgets'));
        self::assertFalse($provider->supports('gadgets'));
    }

    #[Test]
    public function itFetchesOneById(): void
    {
        $one = new Widget(1, 'Alpha');
        $provider = new InMemoryDataProvider('widgets', ['1' => $one]);

        self::assertSame($one, $provider->fetchOne('widgets', '1'));
        self::assertNull($provider->fetchOne('widgets', '999'));
    }

    #[Test]
    public function itFetchesTheWholeCollectionWithEmptyCriteria(): void
    {
        $result = $this->widgets()->fetchCollection('widgets', new CollectionCriteria($this->query()));

        self::assertSame(['1', '2', '3'], $this->ids($result->items));
        self::assertNull($result->total);
        self::assertFalse($result->windowed);
    }

    #[Test]
    public function itWindowsTheCollectionAndReportsThePreWindowTotalWhenCountWanted(): void
    {
        // `wantsCount: true` is the handler's COUNT opt-in: the provider counts the
        // pre-window total and reports it, so the render can emit meta.page.total / last.
        $result = $this->widgets()->fetchCollection('widgets', new CollectionCriteria(
            $this->query(),
            window: new OffsetWindow(1, 1),
            wantsCount: true,
        ));

        self::assertSame(['2'], $this->ids($result->items));
        self::assertSame(3, $result->total);
        self::assertTrue($result->windowed);
    }

    #[Test]
    public function itWindowsCountFreeByDefaultReportingHasMoreNotATotal(): void
    {
        // The count-free default: no `wantsCount`, so the provider fetches count-free — a
        // null total, `windowed` true, and `hasMore` from the window+1 probe.
        $result = $this->widgets()->fetchCollection('widgets', new CollectionCriteria(
            $this->query(),
            window: new OffsetWindow(1, 1),
        ));

        self::assertSame(['2'], $this->ids($result->items));
        self::assertNull($result->total);
        self::assertTrue($result->windowed);
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function itReportsNoFurtherPageOnTheLastWindow(): void
    {
        $result = $this->widgets()->fetchCollection('widgets', new CollectionCriteria(
            $this->query(),
            window: new OffsetWindow(2, 2),
        ));

        self::assertSame(['3'], $this->ids($result->items));
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function itRejectsAWindowShapeItCannotExecute(): void
    {
        $window = new class implements WindowInterface {};

        $this->expectException(\LogicException::class);

        $this->widgets()->fetchCollection('widgets', new CollectionCriteria(
            $this->query(),
            window: $window,
        ));
    }

    private function widgets(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('widgets', [
            '1' => new Widget(1, 'Charlie'),
            '2' => new Widget(2, 'Bravo'),
            '3' => new Widget(3, 'Alpha'),
        ]);
    }

    private function query(): QueryParameters
    {
        return new QueryParameters([], [], [], [], []);
    }

    /**
     * @param iterable<object> $items
     *
     * @return list<string>
     */
    private function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            self::assertInstanceOf(Widget::class, $item);
            $ids[] = (string) $item->id;
        }

        return $ids;
    }
}
