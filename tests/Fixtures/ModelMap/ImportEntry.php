<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap;

/**
 * The POPO the explicit in-memory `imports` registration serves — its distinctive title
 * proves a response came from the explicit tier, not the shadowed auto pair.
 *
 * @internal
 */
final class ImportEntry
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
