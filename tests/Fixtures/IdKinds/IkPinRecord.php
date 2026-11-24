<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * The in-memory twin of {@see IkPin}.
 */
final class IkPinRecord
{
    public function __construct(
        public string $pin_type,
        public string $pin_id,
    ) {}
}
