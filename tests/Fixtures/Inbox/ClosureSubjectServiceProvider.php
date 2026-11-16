<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers {@see ClosureSubjectResource} over empty in-memory stores.
 *
 * @internal
 */
final class ClosureSubjectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RfpResource::class, InboxCampaignResource::class, ClosureSubjectResource::class])->ignoreRoutes();

        foreach (['inbRfps', 'inbCampaigns', 'inbClosureItems'] as $type) {
            JsonApi::provider(new InMemoryDataProvider($type, []));
        }
    }
}
