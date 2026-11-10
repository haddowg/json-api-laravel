<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Leads filtered by what their brand and templates hold.
 */
final class LeadResource extends AbstractResource
{
    public static string $type = 'ctLeads';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('campaign', 'ctCampaigns'),
            BelongsTo::make('brand', 'ctBrands'),
            BelongsToMany::make('templates', 'ctTemplates'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereThrough::make('brandName', 'brand.name'),
            WhereThrough::make('templateName', 'templates.name'),
            WhereHas::make('branded', 'brand'),
            WhereDoesntHave::make('unbranded', 'brand'),
        ];
    }
}
