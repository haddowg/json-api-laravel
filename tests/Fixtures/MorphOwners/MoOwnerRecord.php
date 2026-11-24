<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

/**
 * The in-memory twins of {@see MoPost} and {@see MoVideo}, a class each.
 */
abstract class MoOwnerRecord
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
    ) {}
}
