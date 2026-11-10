<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

/**
 * A campaign of one advertiser and one brand. Its countable `leads` scope a `usesTemplate`
 * filter to the related and relationship endpoints and to their counts.
 */
final class CampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('advertiser', 'advertisers'),
            BelongsTo::make('brand', 'brands'),
            HasMany::make('leads', 'leads')->countable()->withFilters(WhereThroughIn::make('usesTemplate', 'templates')),
        ];
    }
}
