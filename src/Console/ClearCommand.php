<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Console;

use haddowg\JsonApiLaravel\Discovery\DiscoverySnapshotWriter;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\Scope\ScopeTables;
use Illuminate\Console\Command;

/**
 * Removes the warmed JSON:API OpenAPI artifacts — the `optimize:clear`
 * pipeline hook, the twin of {@see OptimizeCommand}. After clearing, the controllers fall
 * back to lazy-building the document on the next request.
 */
final class ClearCommand extends Command
{
    protected $signature = 'jsonapi:clear';

    protected $description = 'Remove the warmed JSON:API OpenAPI document + JSON Schema artifacts.';

    public function handle(ArtifactStore $store, DiscoverySnapshotWriter $snapshot, ScopeTables $scopes): int
    {
        $store->clear();
        $scopes->clear();

        $cachePath = config('jsonapi.discovery.cache');
        if (\is_string($cachePath) && $cachePath !== '') {
            $snapshot->clear($cachePath);
        }

        $this->info('JSON:API OpenAPI artifacts cleared.');

        return self::SUCCESS;
    }
}
