<?php

declare(strict_types=1);

namespace Workbench\App\TypedMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A board of cards, with one pinned card. Its own documents declare no meta, but its
 * related endpoints serve cards, whose documents do.
 */
#[AsJsonApiResource]
final class TmBoardResource extends AbstractResource
{
    public static string $type = 'tm-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('cards', 'tm-cards'),
            BelongsTo::make('pinned', 'tm-cards')->nullable(),
        ];
    }
}
