<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A library — a plain mutable domain object. `items` is the mixed polymorphic to-many set
 * (tracks + albums + artists) the `MorphToMany('items')` relation reads off the parent.
 */
final class Library
{
    /**
     * @param ?User                     $owner the library's owner
     * @param list<Track|Album|Artist> $items the mixed polymorphic member set
     */
    public function __construct(
        public string $id = '',
        public ?User $owner = null,
        public array $items = [],
    ) {}
}
