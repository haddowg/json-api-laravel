<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Unscanned\TenantSuspended;
use Illuminate\Support\ServiceProvider;

/**
 * Wires all three cases the error-catalogue seam has to tell apart, over two sibling
 * directories that are equally "somewhere under the project":
 *
 *  - `ErrorCatalog/Scanned` is added to discovery, so {@see Scanned\QuotaExceeded} is
 *    catalogued by the scan alone;
 *  - {@see TenantSuspended} lives outside it and is named in `JsonApi::register()`, so
 *    it is catalogued by registration;
 *  - {@see Unscanned\SubscriptionRequired} lives beside it, named nowhere, so it is
 *    catalogued not at all.
 *
 * Both calls run in `register()` so they land before the package provider's `boot()`
 * reads discovery.
 *
 * @internal
 */
final class ErrorCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__ . '/Scanned']);
        JsonApi::register([TenantSuspended::class]);
    }
}
