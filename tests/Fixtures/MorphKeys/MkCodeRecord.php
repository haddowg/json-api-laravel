<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

/**
 * The in-memory twin of {@see MkCode}.
 */
final class MkCodeRecord
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
