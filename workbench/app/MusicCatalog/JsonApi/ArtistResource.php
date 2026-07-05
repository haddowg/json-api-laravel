<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;
use Workbench\App\MusicCatalog\Domain\Artist as ArtistDomain;
use Workbench\App\MusicCatalog\Models\Artist as ArtistModel;
use Workbench\App\MusicCatalog\Query\FullTextSearch;

/**
 * The `artists` resource type (music-catalog domain, decision 14). Store-provided
 * auto-increment id; a computed read-only `trackCount`; a create-vs-update read-only
 * `createdAt`; and the `albums` HasMany back-reference.
 *
 * `abilities: ['read' => false, 'list' => false]` declares both reads fully public — the
 * byte-compat twin of the Symfony example's `securityRead:false`/`securityList:false`
 * (the OpenAPI projection emits `security: []` on each read).
 */
#[AsJsonApiResource(abilities: [
    Operation::FetchOne->value => false,
    Operation::FetchCollection->value => false,
])]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120)->sortable(),
            Str::make('slug')->sortable(),
            Url::make('website')->nullable(),
            Str::make('bio')->nullable()->maxLength(1000),
            Integer::make('trackCount')
                ->computed()
                ->readOnly()
                ->extractUsing(static function (mixed $model): int {
                    $value = \is_object($model) ? Accessor::get($model, 'track_count') : null;

                    return \is_numeric($value) ? (int) $value : 0;
                }),
            DateTime::make('createdAt')->storedAs('created_at')->readOnlyOnUpdate()->sortable(),
            HasMany::make('albums', 'albums'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('slug')->singular(),
            FullTextSearch::make('q', ['name', 'bio']),
        ];
    }

    /**
     * Object-aware so this resource can serve as a polymorphic member of `favorites`
     * (favoritable) and `libraries` (items): only a real Artist (model or POPO) is an
     * `artists` type.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof ArtistModel || $object instanceof ArtistDomain ? 'artists' : '';
    }
}
