<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the provider-less {@see OrphanStandaloneSerializer} explicitly, so a booted
 * app has exactly one unservable standalone-serializer type — the fixture proving the
 * servability warmer walks the serializer channel, not just the resource channel.
 *
 * @internal
 */
final class OrphanStandaloneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([OrphanStandaloneSerializer::class]);
    }
}
