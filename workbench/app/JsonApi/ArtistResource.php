<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\Domain\Artist;

/**
 * The `artists` resource type, re-themed from the Symfony bundle's example (relations
 * and filters dropped — those are Phase 1/3). `readOnly: true` restricts it to the two
 * fetch operations, so only `GET /api/artists` and `GET /api/artists/{id}` are routed.
 */
#[AsJsonApiResource(readOnly: true)]
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
                ->extractUsing(static fn(mixed $artist): int => $artist instanceof Artist ? $artist->trackCount : 0),
            DateTime::make('createdAt')->readOnlyOnUpdate()->sortable(),
        ];
    }
}
