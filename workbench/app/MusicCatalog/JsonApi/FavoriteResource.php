<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `favorites` resource type (music-catalog domain) — the polymorphic to-one witness:
 * `favoritable` points at a track, album, or artist, resolved from the related object's own
 * type. On Eloquent it is a native `morphTo`; on the in-memory witness it is a direct object
 * reference. Both store the pair `identifiedByColumns()` names, so the linkage renders from it. `cannotBeIncluded()` (include safeguard A) keeps `?include=favoritable` a 400
 * while the related/relationship endpoints still render.
 */
#[AsJsonApiResource]
final class FavoriteResource extends AbstractResource
{
    public static string $type = 'favorites';

    /**
     * The morph alias each target type is stored under, as the Eloquent morph map names it.
     */
    public const array TYPE_MAP = ['mc_track' => 'tracks', 'mc_album' => 'albums', 'mc_artist' => 'artists'];

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            DateTime::make('favoritedAt')->example('2024-06-01T09:30:00+00:00')->storedAs('favorited_at')->readOnlyOnUpdate(),
            // Targets `public-profiles`, not `users`: `favorites` lives on the default
            // server and `users` is admin-only, so a relation naming it here would expose
            // `GET /favorites/{id}/user` to a type this server has no serializer for. The
            // member name stays `user`, so the endpoint path is unchanged.
            BelongsTo::make('user', 'public-profiles'),
            MorphTo::make('favoritable', ['tracks', 'albums', 'artists'])
                ->identifiedByColumns(type: 'favoritable_type', id: 'favoritable_id', typeMap: self::TYPE_MAP)
                ->extractUsing(static function (mixed $favorite): ?object {
                    $target = \is_object($favorite) ? Accessor::get($favorite, 'favoritable') : null;

                    return \is_object($target) ? $target : null;
                })
                ->cannotBeIncluded(),
        ];
    }

    public function filters(): array
    {
        return [
            // On Eloquent this compares the model's own morphTo columns through the morph
            // map; in memory, the pair identifiedByColumns() names.
            WhereMorph::make('favoritable')
                ->describedAs('Favorites of this resource, or of any resource of the type when no id is sent.')
                ->example(['type' => 'albums', 'id' => ['1']]),
            WhereSet::make('dated', 'favorited_at')
                ->default(true)
                ->describedAs('Whether the favorite records when it was made. Undated favorites are left out unless asked for.'),
        ];
    }
}
