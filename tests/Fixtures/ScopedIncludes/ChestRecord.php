<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

/**
 * The in-memory twin of {@see Chest}.
 */
final class ChestRecord
{
    /**
     * @param list<CoinRecord|MarkRecord> $holdings
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $holdings = [],
    ) {}
}
