<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NamedExtensions;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers {@see WidgetResource} with an empty in-memory provider.
 *
 * @internal
 */
final class NamedExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([WidgetResource::class]);
        JsonApi::provider(new InMemoryDataProvider('widgets', []));
    }
}
