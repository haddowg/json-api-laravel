<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

/**
 * A user served by {@see FkUserProvider}, not by a model. Its foreign-key relations hold
 * nothing on it; {@see $pinned} and {@see $favourite} are the items its provider loads onto
 * it, read off it.
 */
final class FkUser
{
    /**
     * @param list<object> $pinned
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $pinned = [],
        public ?object $favourite = null,
    ) {}
}
