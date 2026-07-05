<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `favorites` resource type (music-catalog domain) — the polymorphic to-one witness:
 * `favoritable` points at a track, album, or artist, resolved from the related object's own
 * type. On Eloquent it is a native `morphTo`; on the in-memory witness it is a direct object
 * reference. `cannotBeIncluded()` (include safeguard A) keeps `?include=favoritable` a 400
 * while the related/relationship endpoints still render.
 */
#[AsJsonApiResource]
final class FavoriteResource extends AbstractResource
{
    public static string $type = 'favorites';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('favoritedAt')->storedAs('favorited_at')->readOnlyOnUpdate(),
            BelongsTo::make('user', 'users'),
            MorphTo::make('favoritable', ['tracks', 'albums', 'artists'])
                ->extractUsing(static function (mixed $favorite): ?object {
                    $target = \is_object($favorite) ? Accessor::get($favorite, 'favoritable') : null;

                    return \is_object($target) ? $target : null;
                })
                ->cannotBeIncluded(),
        ];
    }
}
