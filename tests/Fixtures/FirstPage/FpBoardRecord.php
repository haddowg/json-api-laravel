<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

final class FpBoardRecord
{
    /**
     * @param list<FpCardRecord>  $cards
     * @param list<FpLabelRecord> $labels
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $cards = [],
        public array $labels = [],
    ) {}

    /**
     * The board's cards, most recently updated first.
     *
     * @return list<FpCardRecord>
     */
    public function getNewestCards(): array
    {
        $cards = $this->cards;
        \usort($cards, static fn(FpCardRecord $a, FpCardRecord $b): int => $b->updatedAt <=> $a->updatedAt);

        return $cards;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public function taggedPairs(): array
    {
        return FirstPageSeed::taggedPairs((int) $this->id);
    }
}
