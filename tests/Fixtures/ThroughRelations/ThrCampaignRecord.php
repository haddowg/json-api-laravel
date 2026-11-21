<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

/**
 * An in-memory campaign.
 */
final class ThrCampaignRecord
{
    /**
     * @param list<ThrLeadRecord> $leads
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $budget = 0,
        public ?\DateTimeImmutable $start_date = null,
        public ?\DateTimeImmutable $archived_at = null,
        public ?ThrAdvertiserRecord $advertiser = null,
        public array $leads = [],
    ) {}
}
