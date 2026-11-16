<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

final class FpDeskRecord
{
    /**
     * @param list<FpCardRecord> $cards
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $cards = [],
    ) {}
}
