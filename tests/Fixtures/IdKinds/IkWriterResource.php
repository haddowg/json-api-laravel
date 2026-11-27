<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * A writer's books through a native `hasMany`, through a to-one foreign key and through a
 * field-only foreign key, the one book it features through a native `hasOne`, and the
 * writers it seats and is seated by, both read off one seat table.
 */
final class IkWriterResource extends AbstractResource
{
    public static string $type = 'ikWriters';

    public function fields(): array
    {
        return [
            IdKinds::id(self::$type)->allowClientId(),
            Str::make('name')->sortable(),
            HasMany::make('books', 'ikBooks')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            HasMany::make('authored', 'ikBooks', 'writer')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            HasMany::make('penned', 'ikBooks', 'writerRef')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            HasOne::make('featured', 'ikBooks')->nullable(),
            MorphMany::make('originated', 'ikBooks', 'origin')->countable()->paginate(PagePaginator::make()->withDefaultPerPage(10)),
            $this->seats('peers', 'owner', 'peer'),
            $this->seats('followers', 'peer', 'owner'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make()->acceptsList(),
            WhereIdIn::make('books')->through('books')->acceptsList(),
            Where::make('peerName', 'name')->through('peers'),
            Where::make('followerName', 'name')->through('followers'),
        ];
    }

    /**
     * Writers seated beside writers: one seat table read from either of its two keys.
     */
    private function seats(string $name, string $parentSide, string $memberSide): BelongsToManyBuilder
    {
        $relation = BelongsToMany::make($name, self::$type)
            ->fields(Integer::make('rank')->nullable())
            ->countable()
            ->paginate(PagePaginator::make()->withDefaultPerPage(10));

        // The witness holds no pivot rows to filter.
        return config('id_kinds.store') === 'memory' ? $relation : $relation->withFilters(
            Where::make('rank', 'rank')->through('pivot'),
            WhereIdIn::make('seatedBy')->through('pivot.' . $parentSide),
            WhereIdIn::make('seating')->through('pivot.' . $memberSide),
        );
    }

    public function isCountable(): bool
    {
        return true;
    }

    public function getType(mixed $object): string
    {
        return $object instanceof IkWriter || $object instanceof IkWriterRecord ? self::$type : '';
    }
}
