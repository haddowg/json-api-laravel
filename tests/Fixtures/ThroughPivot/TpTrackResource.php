<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Tracks on playlists through pivot rows that hold who added each track and who audited it.
 * The playlists' filters and sorts reach those rows with a leading `pivot`, and the
 * deprecated `pivot.`-prefixed traversals sit beside their migrated forms.
 */
#[AsJsonApiResource(readOnly: true)]
final class TpTrackResource extends AbstractResource
{
    public static string $type = 'tpTracks';


    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('album', 'tpAlbums')->nullable(),
            BelongsToMany::make('playlists', 'tpPlaylists')
                ->fields(Integer::make('position'), DateTime::make('addedAt')->storedAs('added_at')->readOnly())
                ->withFilters(...self::playlistFilters())
                ->withSorts(...self::playlistSorts()),
            // The same pivot rows on cursor pages, newest addition first by default.
            $this->cursorPlaylists(),
        ];
    }

    public function filters(): array
    {
        // A pivot on the type's first relation, going on past the pivot row.
        return self::inMemory() ? [] : [Where::make('playlistAdder', 'name')->through('playlists.pivot.addedBy')];
    }

    private function cursorPlaylists(): BelongsToMany
    {
        $relation = BelongsToMany::make('cursorPlaylists', 'tpPlaylists')
            ->fields(Integer::make('position'), DateTime::make('addedAt')->storedAs('added_at')->readOnly())
            ->withSorts(...self::playlistSorts())
            ->paginate(CursorPaginator::make()->withDefaultSize(1));

        return (self::inMemory() && config('through_pivot.memory_pivot_order') !== true ? $relation : $relation->defaultSort(new SortDirective(SortByField::make('added', 'addedAt')->through('pivot'), descending: true)))->build();
    }

    /**
     * An in-memory store holds no pivot rows, so it can't name the relations one holds, and
     * declares only the paths ending on a pivot field and two deprecated `pivot.` traversals,
     * which it refuses with a `400`.
     */
    private static function inMemory(): bool
    {
        return config('through_pivot.store') === 'memory';
    }

    /**
     * @return list<FilterInterface|FilterBuilderInterface>
     */
    private static function playlistFilters(): array
    {
        $fields = [
            DateRange::make('addedAt', 'addedAt')->through('pivot'),
            Where::make('position', 'position')->through('pivot')->integer(),
        ];

        $legacy = [WhereThrough::make('legacyAddedBy', 'pivot.addedBy.name'), WhereDoesntHave::make('legacyUnaudited', 'pivot.auditor')];

        return self::inMemory() ? [...$fields, ...$legacy] : [
            ...$fields,
            Where::make('addedBy', 'name')->through('pivot.addedBy'),
            WhereIdIn::make('adder')->through('pivot.addedBy')->acceptsList(),
            Where::make('auditedBy', 'name')->through('pivot.auditor'),
            WhereDoesntHave::make('unaudited', 'auditor')->through('pivot'),
            ...$legacy,
            WhereThroughIn::make('legacyAdder', 'pivot.addedBy'),
            WhereThrough::make('legacyAuditedBy', 'pivot.auditor.name'),
            // A family resolving to the deprecated path past the pivot row to the owner, a
            // model no type serves, which route registration can't see.
            FilterFamily::make('owner', static fn(string $suffix, JsonApiRequestInterface $request): FilterInterface => WhereThrough::make('owner.' . $suffix, 'pivot.owner.' . $suffix)->build()),
        ];
    }

    /**
     * @return list<SortByField>
     */
    private static function playlistSorts(): array
    {
        $added = SortByField::make('added', 'addedAt')->through('pivot');

        return self::inMemory() ? [$added] : [$added, SortByField::make('adder', 'name')->through('pivot.addedBy')];
    }
}
