<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotMembership;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership\CrateRow;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership\RecordRow;

/**
 * The records are the list on the stored crate's `records` property.
 *
 * @internal
 */
final class InMemoryPivotMembershipTest extends PivotMembershipTestCase
{
    protected function store(): string
    {
        return 'memory';
    }

    protected function storedRecords(int $crate): array
    {
        $provider = $this->app?->make('pm.crates');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $row = $provider->store()->find((string) $crate);
        self::assertInstanceOf(CrateRow::class, $row);

        $ids = \array_map(static fn(RecordRow $record): string => $record->id, $row->records);
        \sort($ids);

        return $ids;
    }
}
