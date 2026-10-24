<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

/**
 * The in-memory twin of {@see Sticker}.
 */
final class StickerRecord
{
    public function __construct(
        public string $id = '',
        public string $label = '',
    ) {}
}
