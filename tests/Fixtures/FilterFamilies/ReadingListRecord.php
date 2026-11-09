<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

/**
 * The in-memory twin of {@see ReadingList}. The in-memory provider holds no pivot data.
 */
final class ReadingListRecord
{
    /**
     * @param list<BookRecord> $rankedBooks
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $rankedBooks = [],
    ) {}
}
