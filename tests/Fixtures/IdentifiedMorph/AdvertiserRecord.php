<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

final class AdvertiserRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?PersonRecord $owner = null,
        public bool $hidden = false,
    ) {}
}
