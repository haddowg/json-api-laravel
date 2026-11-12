<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\SortExample;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class SortExampleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GadgetResource::class]);
        JsonApi::provider(new InMemoryDataProvider(
            'gadgets',
            ['1' => new Gadget('1', 'Widget', 10)],
            identify: static fn(object $gadget): string => $gadget instanceof Gadget ? $gadget->id : '',
        ));
    }
}
