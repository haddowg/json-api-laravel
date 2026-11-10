<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Campaigns of one brand, counting their leads through a foreign key and their shortlist
 * through a pivot table. Each relation scopes a `leadBrand` filter to its related endpoint
 * and its counts.
 */
final class CampaignResource extends AbstractResource
{
    public static string $type = 'ctCampaigns';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('brand', 'ctBrands'),
            HasMany::make('leads', 'ctLeads')
                ->paginate(PagePaginator::make())
                ->countable()
                ->withFilters(WhereThrough::make('leadBrand', 'brand.name')),
            BelongsToMany::make('shortlist', 'ctLeads')
                ->paginate(PagePaginator::make())
                ->countable()
                ->withFilters(WhereThrough::make('leadBrand', 'brand.name')),
        ];
    }
}
