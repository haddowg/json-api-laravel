<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

/**
 * The in-memory twin of {@see Coin}.
 */
final class CoinRecord
{
    /**
     * @param list<MarkRecord> $marks
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $hidden = false,
        public array $marks = [],
    ) {}
}
