<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

/**
 * The in-memory twin of {@see Campaign}.
 */
final class CampaignRecord
{
    /**
     * @param list<LeadRecord> $leads
     * @param list<LeadRecord> $shortlist
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?BrandRecord $brand = null,
        public array $leads = [],
        public array $shortlist = [],
    ) {}
}
