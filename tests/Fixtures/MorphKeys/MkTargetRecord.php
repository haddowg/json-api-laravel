<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

/**
 * The in-memory twin of a counter, a badge or a token, keyed by its storage key.
 */
final class MkTargetRecord
{
    public function __construct(
        public string $type,
        public int $id,
        public string $name,
    ) {}
}
