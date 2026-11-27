<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;

/**
 * A book's writer, labels, native `morphTo` subject, columns-identified origin and link-row
 * pins, with a filter over each.
 */
final class IkBookResource extends AbstractResource
{
    public static string $type = 'ikBooks';

    public function fields(): array
    {
        return [
            IdKinds::id(self::$type)->allowClientId(),
            Str::make('title')->sortable(),
            Str::make('writerRef')->storedAs('writer_ref')->hidden(),
            BelongsTo::make('writer', 'ikWriters')->nullable(),
            BelongsToMany::make('labels', 'ikLabels')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            MorphTo::make('subject', ['ikWriters', 'ikLabels'])->nullable(),
            MorphTo::make('origin', ['ikWriters', 'ikLabels'])
                ->identifiedByColumns(type: 'origin_type', id: 'origin_id')
                ->nullable()
                ->readOnly(),
            MorphToMany::make('pins', ['ikWriters', 'ikLabels'])
                ->through(IkPin::class)
                ->identifiedByRows(owner: 'book', type: 'pin_type', id: 'pin_id')
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint()
                ->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make()->acceptsList(),
            WhereIdIn::make('writer')->through('writer')->acceptsList(),
            WhereIdIn::make('labels')->through('labels')->acceptsList(),
            Where::make('writerName', 'name')->through('writer'),
            WhereHas::make('labelled', 'labels'),
            WhereDoesntHave::make('unlabelled', 'labels'),
            WhereMorph::make('subject'),
            WhereMorph::make('origin'),
            WhereMorph::make('pinned', 'pins'),
            Contains::make('title'),
            Where::make('writerKey', 'writer_id'),
            WhereIn::make('writerKeys', 'writer_id'),
            Where::make('writerKeyNot', 'writer_id', '<>'),
            WhereNotIn::make('writerKeysNot', 'writer_id'),
            Where::make('writerKeyFrom', 'writer_id', '>='),
        ];
    }

    public function isCountable(): bool
    {
        return true;
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(10),
            CursorPaginator::make()->withDefaultSize(10),
        )->default('page');
    }

    public function getType(mixed $object): string
    {
        return $object instanceof IkBook || $object instanceof IkBookRecord ? self::$type : '';
    }
}
