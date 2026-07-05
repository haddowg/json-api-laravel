<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * The `docker compose up` demo wiring (PLAN Phase 5 docker item). It applies the shared
 * {@see CatalogConfig} — the exact Laravel translation of the Symfony example's
 * `config/packages/json_api.yaml` (base URI, the default + admin servers, OpenAPI
 * info/tags/security, the include-depth + page-size bounds, the atomic endpoint) — so the
 * container serves the full music-catalog domain over `testbench serve` with the same
 * surface the byte-compat export and the docs snippets describe.
 *
 * It is listed only by `testbench.docker.yaml` (never the main `testbench.yaml` nor any
 * test), paired with {@see MusicCatalogEloquentServiceProvider} for the resource/model
 * wiring — so it can never co-register with, or disturb, the per-phase suites. Config is
 * set in `register()` so it lands before the package provider's `boot()` reads the servers.
 */
final class MusicCatalogDemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        CatalogConfig::apply($config);
    }
}
