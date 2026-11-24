<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\WhereMorph;

/**
 * Notes about a counter, a badge or a token, and pinned to others.
 */
final class MkNoteResource extends AbstractResource
{
    public static string $type = 'mkNotes';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['mkCounters', 'mkBadges', 'mkTokens'])
                ->identifiedByColumns(type: 'subject_type', id: 'subject_id')
                ->nullable(),
            MorphToMany::make('pins', ['mkCounters', 'mkBadges', 'mkTokens'])
                ->through(MkPin::class)
                ->identifiedByRows(owner: 'note', type: 'pin_type', id: 'pin_id')
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereMorph::make('subject'),
            WhereMorph::make('pinned', 'pins'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkNote || $object instanceof MkNoteRecord ? static::$type : '';
    }
}
