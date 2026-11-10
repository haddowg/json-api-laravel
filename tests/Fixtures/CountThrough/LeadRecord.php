<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

/**
 * The in-memory twin of {@see Lead}.
 */
final class LeadRecord
{
    /**
     * @param list<TemplateRecord> $templates
     */
    public function __construct(
        public string $id,
        public string $name,
        public CampaignRecord $campaign,
        public ?BrandRecord $brand = null,
        public array $templates = [],
    ) {}
}
