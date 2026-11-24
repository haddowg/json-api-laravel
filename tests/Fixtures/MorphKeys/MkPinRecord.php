<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

/**
 * The in-memory twin of {@see MkPin}.
 */
final class MkPinRecord
{
    public function __construct(
        public string $pin_type,
        public string $pin_id,
    ) {}
}
