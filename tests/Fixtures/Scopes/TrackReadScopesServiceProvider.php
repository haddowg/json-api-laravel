<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Replaces the music catalogue's resolver and scope catalogue with {@see TrackReadScopes}.
 * Registered after the catalogue's own wiring, so its bindings win.
 */
final class TrackReadScopesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TrackReadScopes::class);
        $this->app->alias(TrackReadScopes::class, OperationScopeResolverInterface::class);
        $this->app->alias(TrackReadScopes::class, ScopeCatalogInterface::class);
    }
}
