<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Routing;

use haddowg\JsonApiLaravel\Http\JsonSchemaController;
use haddowg\JsonApiLaravel\Http\OpenApiController;
use haddowg\JsonApiLaravel\Http\OpenApiUiController;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfig;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Routing\Router;

/**
 * Emits the OpenAPI documentation routes (PLAN decision 11), gated behind the same
 * expose rule the Symfony bundle's `OpenApiRouteLoader` uses — `app.debug` OR
 * `jsonapi.openapi.expose_in_prod` — and only when `jsonapi.openapi.enabled` is true.
 *
 * In **separate** multi-server mode it emits, at the application root (documentation is
 * API-wide, not under a server's route prefix):
 *  - `GET {json.path}` (default `/docs.json`) → the default server's document
 *    ({@see OpenApiController}, route name {@see OpenApiUiController::DOCUMENT_ROUTE}).
 *  - `GET /{server}/docs.json` per named (non-default) server.
 *  - `GET {json_schema.path}` (default `/schemas.json`) + `GET /{server}/schemas.json`,
 *    gated additionally by `json_schema.enabled` ({@see JsonSchemaController}).
 *  - `GET {ui.path}` (default `/docs`) → the viewer ({@see OpenApiUiController}), gated
 *    additionally by `ui.enabled`.
 *
 * In **combined** multi-server mode only the single `{json.path}` / `{json_schema.path}`
 * routes are emitted (no per-server route); the controllers serve the combined document.
 *
 * Because registration is a pure function of config + the declared server names, it is
 * `route:cache`-safe and is guarded by the same `routesAreCached()` check as the CRUD
 * routes.
 */
final class OpenApiRouteRegistrar
{
    /**
     * @param list<string> $serverNames the declared server names (`default` included)
     */
    public function __construct(
        private readonly OpenApiConfig $config,
        private readonly array $serverNames,
        private readonly bool $debug,
    ) {}

    /**
     * Registers the documentation routes on `$router` when the expose gate passes.
     */
    public function register(Router $router): void
    {
        if (!$this->config->enabled || !($this->debug || $this->config->exposeInProd)) {
            return;
        }

        $this->registerDocumentRoutes($router);

        if ($this->config->jsonSchemaEnabled) {
            $this->registerSchemaRoutes($router);
        }

        if ($this->config->ui->enabled) {
            $router->get($this->config->ui->path, OpenApiUiController::class)
                ->name('jsonapi.openapi.ui');
        }
    }

    private function registerDocumentRoutes(Router $router): void
    {
        $router->get($this->config->jsonPath, OpenApiController::class)
            ->name(OpenApiUiController::DOCUMENT_ROUTE);

        if ($this->config->combined) {
            return;
        }

        foreach ($this->namedServers() as $server) {
            $router->get('/' . $server . '/docs.json', OpenApiController::class)
                ->defaults('server', $server)
                ->name(\sprintf('jsonapi.%s.openapi.json', $server));
        }
    }

    private function registerSchemaRoutes(Router $router): void
    {
        $router->get($this->config->jsonSchemaPath, JsonSchemaController::class)
            ->name('jsonapi.openapi.schemas');

        if ($this->config->combined) {
            return;
        }

        foreach ($this->namedServers() as $server) {
            $router->get('/' . $server . '/schemas.json', JsonSchemaController::class)
                ->defaults('server', $server)
                ->name(\sprintf('jsonapi.%s.openapi.schemas', $server));
        }
    }

    /**
     * The named (non-default) servers — the ones that get their own per-server document
     * / schema route.
     *
     * @return list<string>
     */
    private function namedServers(): array
    {
        return \array_values(\array_filter(
            $this->serverNames,
            static fn(string $server): bool => $server !== ServerRegistry::DEFAULT_SERVER,
        ));
    }
}
