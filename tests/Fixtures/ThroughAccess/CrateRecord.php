<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

/**
 * An in-memory crate.
 */
final class CrateRecord
{
    /**
     * @param list<ParcelRecord> $parcels
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $parcels = [],
        public ?CodedRecord $vault = null,
        public ?CodedRecord $safe = null,
        public ?CodedRecord $ledger = null,
        public ?CodedRecord $auditor = null,
        public ?string $subject_type = null,
        public ?string $subject_id = null,
    ) {}
}
