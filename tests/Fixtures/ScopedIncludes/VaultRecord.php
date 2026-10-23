<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

/**
 * The in-memory twin of {@see Vault}. Its `coins` hold every coin, hidden or not, exactly as
 * an unscoped relation read would.
 */
final class VaultRecord
{
    /**
     * @param list<CoinRecord> $coins
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $coins = [],
        public ?CoinRecord $featuredCoin = null,
        public CoinRecord|MarkRecord|null $spotlight = null,
    ) {}
}
