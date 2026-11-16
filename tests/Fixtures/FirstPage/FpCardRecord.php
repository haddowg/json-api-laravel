<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

final class FpCardRecord
{
    /**
     * @param list<FpCommentRecord> $comments
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        public ?FpBoardRecord $board = null,
        public array $comments = [],
    ) {}
}
