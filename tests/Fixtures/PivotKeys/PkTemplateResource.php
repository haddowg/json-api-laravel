<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

final class PkTemplateResource extends AbstractResource
{
    public static string $type = 'campaign-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric()->storedAs('campaign_id'),
            Str::make('name')->sortable(),
            BelongsToMany::make('placeholders', 'placeholder-definitions')
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
            WhereThroughIn::make('definition', 'placeholderRows.definition'),
            WhereThrough::make('definitionIs', 'placeholderRows.definition'),
            WhereThroughIn::make('placeholder', 'placeholders'),
            WhereThrough::make('placeholderName', 'placeholders.name'),
            WhereHas::make('hasPlaceholders', 'placeholders'),
        ];
    }
}
