<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

final class CampaignRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?string $target_type = null,
        public ?string $target_id = null,
    ) {}
}
