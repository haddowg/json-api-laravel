<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;

final class MoNoteResource extends AbstractResource
{
    public static string $type = 'moNotes';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('body'),
            Str::make('ownerId')->storedAs('owner_id')->hidden(),
            MorphTo::make('owner', ['moPosts', 'moVideos'])
                ->identifiedByColumns(type: 'owner_type', id: 'owner_id', typeMap: ['post' => 'moPosts', 'video' => 'moVideos'])
                ->nullable(),
            MorphTo::make('author', ['moPosts', 'moVideos'])->nullable(),
        ];
    }

    public function filters(): array
    {
        return [Contains::make('body')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MoNote || $object instanceof MoNoteRecord ? self::$type : '';
    }
}
