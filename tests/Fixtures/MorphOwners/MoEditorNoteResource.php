<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;

/**
 * Declares a `MorphTo` over a model method that is a plain `belongsTo`.
 */
final class MoEditorNoteResource extends AbstractResource
{
    public static string $type = 'moNotes';

    public function fields(): array
    {
        return [Id::make()->numeric(), MorphTo::make('editor', ['moPosts', 'moVideos'])->nullable()];
    }
}
