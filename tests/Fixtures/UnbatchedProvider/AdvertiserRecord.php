<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

final class AdvertiserRecord
{
    /**
     * @param list<CampaignRecord> $campaigns
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?OwnerRecord $owner = null,
        public array $campaigns = [],
    ) {}
}
