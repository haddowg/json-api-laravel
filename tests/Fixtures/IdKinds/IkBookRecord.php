<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * The in-memory twin of {@see IkBook}.
 */
final class IkBookRecord
{
    /**
     * @param list<IkLabelRecord> $labels
     * @param list<IkPinRecord>   $pins
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?IkWriterRecord $writer = null,
        public ?string $writer_ref = null,
        public array $labels = [],
        public IkWriterRecord|IkLabelRecord|null $subject = null,
        public ?string $origin_type = null,
        public ?string $origin_id = null,
        public array $pins = [],
    ) {}
}
