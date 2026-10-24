<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\WriteOnlyRelations;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations\BoxRecord;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class InMemoryWriteOnlyRelationsTest extends WriteOnlyRelationsTestCase
{
    protected function store(): string
    {
        return 'memory';
    }

    protected function storedWriteOnlyMembers(string $boxId): array
    {
        $provider = $this->app?->make(DataProviderRegistry::class)->forType('boxes');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $box = $provider->store()->find($boxId);
        self::assertInstanceOf(BoxRecord::class, $box);

        return [$box->secretKeeper?->id, \array_map(static fn(object $sticker): string => $sticker->id, $box->stickers)];
    }
}
