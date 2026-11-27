<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot\NpPivotStoreProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see NestedPivotTestCase} with the crates served by a provider that stores their pivot
 * rows without querying the join, so an include renders the stored values from its batched
 * pivot map.
 *
 * @internal
 */
#[CoversNothing]
final class CustomStoreNestedPivotTest extends NestedPivotTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('nested_pivot.store', 'custom');

        return parent::getPackageProviders($app);
    }

    protected function storesPivots(): bool
    {
        return true;
    }

    protected function storesWrittenPivots(): bool
    {
        return false;
    }

    public function test_an_include_reads_the_pivot_map_once_for_every_parent(): void
    {
        foreach (['/api/npShelves?include=crate.records', '/api/npRooms/1?include=shelves.crate.records', '/api/npShelves/1?include=crate.records,altCrate.records'] as $uri) {
            NpPivotStoreProvider::$singleReads = 0;
            NpPivotStoreProvider::$batchSizes = [];

            $this->document($uri);

            self::assertSame(0, NpPivotStoreProvider::$singleReads, $uri);
            self::assertSame([2], NpPivotStoreProvider::$batchSizes, $uri);
        }
    }
}
