<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Model;

/**
 * A radio station, served from a fixed list by
 * {@see \Workbench\App\MusicCatalog\Provider\StationProvider}. It holds none of its plays:
 * they are stored rows that point back at it.
 */
final class Station
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
