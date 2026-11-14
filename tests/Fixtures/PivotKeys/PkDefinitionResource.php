<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;

final class PkDefinitionResource extends AbstractResource
{
    public static string $type = 'placeholder-definitions';

    public function fields(): array
    {
        return [
            Id::make()->uuid()->encodeUsing(new BinaryUuidEncoder()),
            Str::make('name')->sortable(),
            BelongsToMany::make('templates', 'campaign-templates')
                ->fields(Str::make('defaultOverride')->storedAs('default_override')->nullable()->sortable()->build())
                ->withFilters(Where::make('defaultOverride', 'pivot.default_override'))
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name', 'name'),
            WhereIdIn::make('id', 'id'),
            Where::make('idIs', 'id'),
            WhereThroughIn::make('template', 'usages.template'),
            WhereThroughIn::make('usedBy', 'templates'),
        ];
    }
}
