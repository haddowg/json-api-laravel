<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorph;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph\CampaignRecord;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see IdentifiedMorphTestCase} in memory, with a scoping provider hiding advertiser 3.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryIdentifiedMorphTest extends IdentifiedMorphTestCase
{
    protected function store(): string
    {
        return 'memory';
    }

    protected function queries(\Closure $read): ?array
    {
        return null;
    }

    protected function addCampaign(int $id, string $type, int $target): void
    {
        $campaigns = $this->app?->make('identified.campaigns');
        self::assertInstanceOf(InMemoryDataProvider::class, $campaigns);
        $campaigns->store()->save(new CampaignRecord((string) $id, 'Campaign ' . $id, $type, (string) $target));
    }
}
