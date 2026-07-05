<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

/**
 * The backing domain object for the `notes` fixture type (a serializer-override witness)
 * — a plain POPO seeded into the in-memory provider.
 *
 * @internal
 */
final class Note
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
