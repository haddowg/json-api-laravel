<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

/**
 * The in-memory twin of {@see MkNote}.
 */
final class MkNoteRecord
{
    /**
     * @param list<MkPinRecord> $pins
     */
    public function __construct(
        public string $id,
        public ?string $subject_type = null,
        public ?string $subject_id = null,
        public array $pins = [],
    ) {}
}
