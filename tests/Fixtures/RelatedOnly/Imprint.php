<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly;

/**
 * A label imprint, reached only through a {@see Record}, with an optional parent imprint.
 *
 * @internal
 */
final class Imprint
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Imprint $parent = null,
    ) {}
}
