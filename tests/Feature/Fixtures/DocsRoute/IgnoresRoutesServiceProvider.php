<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures\DocsRoute;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Turns off route auto-registration, so the test places the JSON:API routes and decides
 * who serves the OpenAPI documents.
 *
 * @internal
 */
final class IgnoresRoutesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::ignoreRoutes();
    }
}
