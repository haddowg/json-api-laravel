<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

/**
 * The in-memory twin of {@see Campaign}.
 */
final class CampaignRecord
{
    /**
     * @param list<LeadRecord> $leads
     */
    public function __construct(
        public string $id,
        public string $name,
        public AdvertiserRecord $advertiser,
        public BrandRecord $brand,
        public array $leads = [],
    ) {}
}
