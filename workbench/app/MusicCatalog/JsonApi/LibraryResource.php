<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\MusicCatalog\Domain\Library as LibraryDomain;
use Workbench\App\MusicCatalog\Models\Library as LibraryModel;

/**
 * The `libraries` resource type (music-catalog domain) — the polymorphic to-many
 * (over-parity) headline (PLAN decision 14): where the Doctrine reference THROWS on a
 * `MorphToMany`, the Eloquent reference resolves the mixed `items` set (tracks + albums +
 * artists) natively via {@see LibraryModel::libraryItems()} (three `morphedByMany` relations
 * over one polymorphic pivot), read off the parent by the `items` `extractUsing`; the
 * in-memory witness reads the same mixed list off the POPO. Each member renders through its
 * own per-type serializer.
 *
 * It is also registered on the `admin` server, because {@see UserResource}'s `library`
 * relation exposes `GET /admin/users/{id}/library` and whitelists `library` for
 * inclusion. A related endpoint returns its target as primary data, so the target type
 * has to be registered wherever the parent is.
 */
#[AsJsonApiResource(server: ['default', 'admin'])]
final class LibraryResource extends AbstractResource
{
    public static string $type = 'libraries';

    public function fields(): array
    {
        return [
            Id::make(),
            // Targets `public-profiles` rather than the admin-only `users`: the related
            // endpoint `GET /libraries/{id}/owner` is served on the default surface, which
            // registers the curated view of the User row and not the full record.
            BelongsTo::make('owner', 'public-profiles'),
            MorphToMany::make('items', ['tracks', 'albums', 'artists'])
                ->extractUsing(static function (mixed $library): array {
                    if ($library instanceof LibraryModel) {
                        return $library->libraryItems();
                    }
                    if ($library instanceof LibraryDomain) {
                        return $library->items;
                    }

                    return [];
                }),
        ];
    }
}
