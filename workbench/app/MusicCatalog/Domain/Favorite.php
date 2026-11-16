<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A favorite — a plain mutable domain object. `favoritable` is the resolved polymorphic
 * to-one member (a Track|Album|Artist), read directly off the object by the `MorphTo`
 * relation; null → `data: null`. `favoritable_type` and `favoritable_id` hold the stored pair,
 * the type as the Eloquent morph alias, as the `favorites` table does.
 */
final class Favorite
{
    public function __construct(
        public string $id = '',
        public ?\DateTimeImmutable $favorited_at = null,
        public ?User $user = null,
        public ?object $favoritable = null,
        public ?string $favoritable_type = null,
        public ?string $favoritable_id = null,
    ) {}
}
