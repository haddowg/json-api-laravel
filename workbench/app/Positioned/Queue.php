<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

/**
 * A queue on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Queue}.
 */
final class Queue
{
    /**
     * @param list<Song>   $songs
     * @param list<\Workbench\App\Models\MixRow>      $mixRows
     * @param list<\Workbench\App\Models\LooseMixRow> $looseMixRows
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $songs = [],
        public array $mixRows = [],
        public array $looseMixRows = [],
    ) {}
}
