<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PaginatorEvent\GadgetResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\PaginatorEvent\PaginatorEventServiceProvider;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a listener reads from {@see AfterFetchCollectionEvent::$paginator} on
 * `GET /gadgets`: the request-resolved paginator, or null when no pagination ran.
 *
 * @internal
 */
final class AfterFetchCollectionPaginatorTest extends Orchestra
{
    /** @var list<PaginatorInterface|null> */
    private array $seen = [];

    protected function setUp(): void
    {
        GadgetResource::reset();

        parent::setUp();

        Event::listen(AfterFetchCollectionEvent::class, function (AfterFetchCollectionEvent $event): void {
            $this->seen[] = $event->paginator;
        });
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            PaginatorEventServiceProvider::class,
        ];
    }

    #[Test]
    public function aMultiPaginatorArrivesAsTheChildTheRequestSelected(): void
    {
        GadgetResource::$paginator = MultiPaginator::make(
            PagePaginator::make(),
            CursorPaginator::make(),
        )->default('cursor');

        $this->fetch('/api/gadgets');
        $this->fetch('/api/gadgets?page[kind]=page&page[size]=2');

        self::assertCount(2, $this->seen);
        [$default, $selected] = $this->seen;

        self::assertInstanceOf(CursorPaginator::class, $default);
        self::assertSame('cursor', $default->kind());
        self::assertInstanceOf(PagePaginator::class, $selected);
        self::assertSame('page', $selected->kind());
    }

    #[Test]
    public function aPagePaginatorArrivesAsDeclared(): void
    {
        $paginator = PagePaginator::make();
        GadgetResource::$paginator = $paginator;

        $this->fetch('/api/gadgets?page[number]=1&page[size]=2');

        self::assertSame([$paginator], $this->seen);
    }

    #[Test]
    public function anOffsetPaginatorArrivesAsDeclared(): void
    {
        $paginator = OffsetPaginator::make();
        GadgetResource::$paginator = $paginator;

        $this->fetch('/api/gadgets?page[offset]=1&page[limit]=2');

        self::assertSame([$paginator], $this->seen);
    }

    #[Test]
    public function aCursorPaginatorArrivesAsDeclared(): void
    {
        $paginator = CursorPaginator::make();
        GadgetResource::$paginator = $paginator;

        $this->fetch('/api/gadgets?page[size]=2');

        self::assertSame([$paginator], $this->seen);
    }

    #[Test]
    public function aFetchAllCollectionCarriesNoPaginator(): void
    {
        GadgetResource::$paginator = null;

        $this->fetch('/api/gadgets');

        self::assertSame([null], $this->seen);
    }

    #[Test]
    public function aSingularFilterCollapseCarriesNoPaginator(): void
    {
        // The resource still declares a paginator; the singular filter skips it.
        self::assertInstanceOf(PaginatorInterface::class, GadgetResource::$paginator);

        $data = $this->fetch('/api/gadgets?filter[slug]=gadget-3')->json('data');

        self::assertIsArray($data);
        self::assertSame('3', $data['id'] ?? null);
        self::assertSame([null], $this->seen);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->get($uri, ['Accept' => 'application/vnd.api+json'])->assertOk();
    }
}
