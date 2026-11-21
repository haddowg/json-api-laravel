<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

/**
 * The in-memory witness's note, holding the same columns as {@see Note}.
 */
final class NoteRecord
{
    /**
     * @param list<ItemRecord> $items
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public int $revision = 1,
        public ?string $source = null,
        public ?string $created_with = null,
        public array $items = [],
        public ?ItemRecord $lead = null,
    ) {}
}
