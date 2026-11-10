<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

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
        public string $status,
        public CampaignRecord $campaign,
        public array $templates = [],
    ) {}
}
