<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A favorite — a plain mutable domain object. `favoritable` is the resolved polymorphic
 * to-one member (a Track|Album|Artist), read directly off the object by the `MorphTo`
 * relation; null → `data: null`.
 */
final class Favorite
{
    public function __construct(
        public string $id = '',
        public ?\DateTimeImmutable $favorited_at = null,
        public ?User $user = null,
        public ?object $favoritable = null,
    ) {}
}
