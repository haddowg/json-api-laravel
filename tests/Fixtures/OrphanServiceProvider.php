<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the provider-less {@see OrphanResource} explicitly (no data provider /
 * persister), so a booted app has exactly one unservable type — the fixture the
 * servability-warmer failure test drives.
 *
 * @internal
 */
final class OrphanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([OrphanResource::class]);
    }
}
