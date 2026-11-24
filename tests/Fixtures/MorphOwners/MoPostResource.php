<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * A post's notes, read through the notes' polymorphic owner.
 */
final class MoPostResource extends AbstractResource
{
    public static string $type = 'moPosts';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            HasMany::make('notes', 'moNotes', 'owner')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            HasMany::make('authoredNotes', 'moNotes', 'author')->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereHas::make('annotated', 'notes'),
            WhereDoesntHave::make('unannotated', 'notes'),
            WhereIdIn::make('notes')->through('notes')->acceptsList(),
            Where::make('noteBody', 'body')->through('notes'),
            WhereHas::make('authored', 'authoredNotes'),
        ];
    }

    public function isCountable(): bool
    {
        return true;
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MoPost || ($object instanceof MoOwnerRecord && $object->type === self::$type) ? self::$type : '';
    }
}
