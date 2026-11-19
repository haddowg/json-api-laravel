<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Advertisers, scoped so the hidden one exists for no caller. Its campaigns are read once
 * without the campaigns' filter default and once with it.
 */
final class ThrAdvertiserResource extends AbstractResource
{
    public static string $type = 'thrAdvertisers';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            HasMany::make('campaigns', 'thrCampaigns')->withoutFilterDefaults('advertiserName'),
            HasMany::make('defaultCampaigns', 'thrCampaigns')->storedAs('campaigns'),
        ];
    }
}
