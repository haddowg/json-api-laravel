<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate;

final class Hub
{
    /**
     * @param list<Spoke> $spokes
     * @param list<Rim>   $rims
     */
    public function __construct(
        public string $id,
        public string $owner,
        public array $spokes = [],
        public array $rims = [],
        public ?Spoke $leadSpoke = null,
    ) {}
}
