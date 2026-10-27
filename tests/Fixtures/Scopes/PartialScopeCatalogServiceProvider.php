<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Replaces the music catalogue's scope catalogue with one that leaves out `batch`, so the
 * Atomic Operations endpoint needs a scope nothing declares.
 */
final class PartialScopeCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScopeCatalogInterface::class, static fn(): ScopeCatalogInterface => new class implements ScopeCatalogInterface {
            public function all(): array
            {
                return [
                    'catalog:write' => 'Create, change and delete albums and their relationships.',
                    'catalog:media' => 'Upload album artwork.',
                    'library:write' => 'Create and change playlists and their tracks.',
                    'library:admin' => 'Delete playlists.',
                ];
            }
        });
    }
}
