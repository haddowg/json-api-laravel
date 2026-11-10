<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

/**
 * Traversal filters over every leaf: `advertiser` and `brand` end two hops away on a to-one,
 * `campaign` on the lead's own to-one and `template` on its to-many, whose ids
 * {@see TemplateIdCodec} encodes. `advertiserIs` is a `WhereThrough` on a relationship and
 * `campaignNames` a list ending on a field. `openFor` and `reach` put a list inside a
 * `WhereAll` and a `WhereAny`, `both` pairs two traversals in one `WhereAll`, and the `via` family resolves to a list per request.
 */
final class LeadResource extends AbstractResource
{
    public static string $type = 'leads';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('status'),
            BelongsTo::make('campaign', 'campaigns'),
            BelongsToMany::make('templates', 'templates'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereThroughIn::make('advertiser', 'campaign.advertiser'),
            WhereThroughIn::make('brand', 'campaign.brand'),
            WhereThroughIn::make('campaign'),
            WhereThroughIn::make('template', 'templates'),
            WhereThrough::make('advertiserIs', 'campaign.advertiser'),
            WhereThrough::make('campaign.name'),
            WhereThroughIn::make('campaignNames', 'campaign.name')->acceptsList(delimited: false),
            WhereAll::make(
                'openFor',
                WhereThroughIn::make('openFor', 'campaign.advertiser'),
                Where::make('open', 'status')->fixed('open'),
            ),
            WhereAll::make(
                'both',
                WhereThroughIn::make('both', 'campaign'),
                WhereThrough::make('both', 'campaign.advertiser'),
            ),
            WhereAny::make(
                'reach',
                WhereThroughIn::make('reach', 'campaign.advertiser'),
                WhereThroughIn::make('reach', 'campaign.brand'),
            ),
            FilterFamily::make('via', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => $suffix === 'advertiser'
                ? WhereThroughIn::make('via.advertiser', 'campaign.advertiser')->build()
                : null)
                ->acceptsList(),
        ];
    }
}
