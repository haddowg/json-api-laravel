<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

/**
 * The in-memory witness's item.
 */
final class ItemRecord
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $extras = [],
    ) {}
}
