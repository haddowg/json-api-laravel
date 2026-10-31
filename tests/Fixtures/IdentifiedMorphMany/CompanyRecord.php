<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

/**
 * An agency, advertiser or publisher held in memory: one class behind all three types.
 */
final class CompanyRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public bool $hidden = false,
    ) {}
}
