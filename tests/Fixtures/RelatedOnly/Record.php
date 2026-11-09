<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly;

/**
 * A record released on an {@see Imprint}.
 *
 * @internal
 */
final class Record
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Imprint $imprint = null,
    ) {}
}
