<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the resource class named by `one-resource.class` over an empty store.
 *
 * @internal
 */
final class OneResourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var class-string<\haddowg\JsonApi\Resource\AbstractResource> $resource */
        $resource = config('one-resource.class');
        JsonApi::register([$resource]);
        JsonApi::provider(new InMemoryDataProvider($resource::$type, []));
    }
}
