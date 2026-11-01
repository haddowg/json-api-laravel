<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PaginatorEvent;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Five gadgets, served by the in-memory provider.
 */
final class PaginatorEventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);

        $gadgets = [];
        foreach (\range(1, 5) as $n) {
            $gadgets[(string) $n] = new Gadget((string) $n, 'gadget-' . $n);
        }

        JsonApi::provider(new InMemoryDataProvider('gadgets', $gadgets));
    }
}
