<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

/**
 * An in-memory parcel.
 */
final class ParcelRecord
{
    /**
     * @param list<TagRecord> $tags
     */
    public function __construct(
        public string $id = '',
        public string $owner_id = '',
        public string $label = '',
        public ?CrateRecord $crate = null,
        public array $tags = [],
    ) {}
}
