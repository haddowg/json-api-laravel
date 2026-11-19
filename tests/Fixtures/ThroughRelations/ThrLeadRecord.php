<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

/**
 * An in-memory lead.
 */
final class ThrLeadRecord
{
    /**
     * @param list<ThrTemplateRecord> $templates
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?ThrCampaignRecord $campaign = null,
        public array $templates = [],
    ) {}
}
