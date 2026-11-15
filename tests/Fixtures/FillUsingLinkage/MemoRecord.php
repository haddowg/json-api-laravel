<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

/**
 * The in-memory witness's memo, with the columns {@see Memo} has.
 */
final class MemoRecord
{
    /**
     * @param list<string>|null $setting_ids
     * @param list<string>|null $label_ids
     */
    public function __construct(
        public ?string $id = null,
        public string $title = '',
        public ?string $setting_id = null,
        public ?array $setting_ids = null,
        public ?string $label_id = null,
        public ?array $label_ids = null,
    ) {}
}
