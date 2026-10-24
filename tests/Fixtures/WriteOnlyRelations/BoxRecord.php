<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

/**
 * The in-memory twin of {@see Box}.
 */
final class BoxRecord
{
    /**
     * @param list<StickerRecord> $stickers
     * @param list<StickerRecord> $badges
     */
    public function __construct(
        public string $id = '',
        public string $label = '',
        public ?KeeperRecord $keeper = null,
        public ?KeeperRecord $secretKeeper = null,
        public array $stickers = [],
        public array $badges = [],
    ) {}
}
