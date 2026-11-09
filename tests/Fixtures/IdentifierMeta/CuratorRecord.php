<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

/**
 * The in-memory twin of {@see Curator}.
 */
final class CuratorRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
