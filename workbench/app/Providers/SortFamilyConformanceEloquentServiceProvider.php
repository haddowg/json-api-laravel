<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\SortCampaign;
use Workbench\App\SortFamily\PlaceholderValueEloquentArm;

/**
 * The Eloquent half of the sort-family conformance wiring: the isolated
 * {@see \Workbench\App\SortFamily\SortCampaignResource} over the reference provider, with
 * the placeholder-value sort arm registered.
 */
final class SortFamilyConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/SortFamily']);

        JsonApi::provider(
            new EloquentDataProvider(['sortCampaigns' => SortCampaign::class], sortArms: [new PlaceholderValueEloquentArm()]),
            priority: -128,
        );
    }
}
