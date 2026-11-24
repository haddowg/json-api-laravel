<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * A video's one note, read through the notes' polymorphic owner.
 */
final class MoVideoResource extends AbstractResource
{
    public static string $type = 'moVideos';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            HasOne::make('note', 'moNotes', 'owner')->nullable(),
            HasMany::make('authoredNotes', 'moNotes')->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereHas::make('noted', 'note'),
            WhereIdIn::make('note')->through('note')->acceptsList(),
            WhereHas::make('authored', 'authoredNotes'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MoVideo || ($object instanceof MoOwnerRecord && $object->type === self::$type) ? self::$type : '';
    }
}
