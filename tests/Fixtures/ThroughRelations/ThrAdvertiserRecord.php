<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

/**
 * An in-memory advertiser.
 */
final class ThrAdvertiserRecord
{
    /**
     * @param list<ThrCampaignRecord> $campaigns
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public bool $hidden = false,
        public array $campaigns = [],
    ) {}
}
