<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DateMessage;

/**
 * The in-memory witness's booking.
 */
final class DmBookingRecord
{
    /**
     * @param ?array<string, mixed> $window
     * @param ?array<string, mixed> $marks
     */
    public function __construct(
        public string $id = '',
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public ?\DateTimeImmutable $day = null,
        public ?\DateTimeImmutable $dueOn = null,
        public ?\DateTimeImmutable $opensAt = null,
        public ?\DateTimeImmutable $closesAt = null,
        public ?\DateTimeImmutable $breakAt = null,
        public ?array $window = null,
        public mixed $until = null,
        public ?array $marks = null,
    ) {}
}
