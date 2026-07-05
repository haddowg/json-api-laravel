<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Console;

use haddowg\JsonApiLaravel\Discovery\DiscoverySnapshotWriter;
use haddowg\JsonApiLaravel\OpenApi\DocumentWarmer;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use Illuminate\Console\Command;

/**
 * Warms the JSON:API discovery + OpenAPI artifacts and eagerly validates servability
 * (PLAN decision 11) — the `optimizes()` pipeline hook (`php artisan optimize` runs it,
 * `optimize:clear` clears it via {@see ClearCommand}).
 *
 * Two phases, mirroring the Symfony bundle's two cache warmers:
 *  - **Servability validation ({@see ServableResourceWarmer}) — mandatory.** Every
 *    problem it reports (a routed type with no provider/persister, a missing/duplicate Id,
 *    a non-discriminating polymorphic candidate, an Eloquent relation with no model method)
 *    fails this command, so a bad config fails the deploy step rather than a runtime 500.
 *  - **Artifact warming ({@see DocumentWarmer}) — optional / non-fatal.** A docs build
 *    failure is logged and reported as a warning but never fails the command; the
 *    controllers' dev lazy-build is the safety net.
 *
 * In local development the artifacts are built lazily on first request (the controllers'
 * fallback), so this command is a deploy-time optimisation, not a prerequisite.
 *
 * When `jsonapi.discovery.cache` names a path it also writes the discovery snapshot
 * (opt-in, the WRITE side of the Phase-0 cache) so a `route:cache`d app skips the scan.
 */
final class OptimizeCommand extends Command
{
    protected $signature = 'jsonapi:optimize';

    protected $description = 'Validate JSON:API servability and warm the OpenAPI document + JSON Schema artifacts.';

    public function handle(ServableResourceWarmer $servable, DocumentWarmer $documents, DiscoverySnapshotWriter $snapshot): int
    {
        // Phase 1 — mandatory servability validation. A problem fails the deploy.
        $problems = $servable->warm();
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            $this->error(\sprintf('JSON:API servability validation failed with %d problem(s).', \count($problems)));

            return self::FAILURE;
        }

        // Phase 2 — the discovery snapshot (opt-in: only when a cache path is configured).
        $cachePath = config('jsonapi.discovery.cache');
        if (\is_string($cachePath) && $cachePath !== '') {
            $snapshot->write($cachePath);
        }

        // Phase 3 — optional artifact warming. Failures are warnings, never fatal.
        $failures = $documents->warm();
        foreach ($failures as $failure) {
            $this->warn($failure);
        }

        $this->info('JSON:API OpenAPI artifacts warmed.');

        return self::SUCCESS;
    }
}
