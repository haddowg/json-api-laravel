<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * A writer's books through a native `hasMany`, through a to-one foreign key and through a
 * field-only foreign key, and the one book it features through a native `hasOne`.
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
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make()->acceptsList(),
            WhereIdIn::make('books')->through('books')->acceptsList(),
        ];
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
