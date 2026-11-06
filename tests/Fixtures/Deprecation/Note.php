<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation;

/**
 * The one domain object every deprecation fixture type stores.
 *
 * @internal
 */
final class Note
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $subtitle = '',
        public ?Note $writer = null,
    ) {}
}
