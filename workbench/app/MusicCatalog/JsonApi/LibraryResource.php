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
 */
#[AsJsonApiResource]
final class LibraryResource extends AbstractResource
{
    public static string $type = 'libraries';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('owner', 'users'),
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
