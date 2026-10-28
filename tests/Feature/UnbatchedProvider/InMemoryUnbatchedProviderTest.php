<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UnbatchedProvider;

use haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider\AdvertiserRecord;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see UnbatchedProviderTestCase} over in-memory records, which every request shares.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryUnbatchedProviderTest extends UnbatchedProviderTestCase
{
    protected function store(): string
    {
        return 'memory';
    }

    protected function storedOwnerOf(string $id): ?string
    {
        return $this->advertiser($id)->owner?->id;
    }

    protected function storedCampaignsOf(string $id): array
    {
        return \array_map(static fn(object $campaign): string => $campaign->id, $this->advertiser($id)->campaigns);
    }

    private function advertiser(string $id): AdvertiserRecord
    {
        /** @var array<string, AdvertiserRecord> $advertisers */
        $advertisers = $this->app?->make('unbatched.advertisers') ?? [];

        return $advertisers[$id];
    }
}
