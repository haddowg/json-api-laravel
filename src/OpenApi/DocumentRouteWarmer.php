<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApiLaravel\Http\OpenApiController;
use Psr\Log\LoggerInterface;

/**
 * Scans the routes for each server's document route at `artisan optimize` and writes the
 * result beside the documents, so no request scans ({@see DocumentRouteLocator}).
 *
 * A server whose document no route serves gets no `describedby` on its links. That is
 * the intent when the expose gate is closed, so it passes silently; with the gate open
 * it's a misconfiguration, so it logs a warning naming the server. A server no JSON:API
 * route is registered for serves nothing to link from, so it gets no warning, and in
 * combined mode the warning needs at least one such server. Like the
 * {@see DocumentWarmer} it never fails the optimize step, and it runs before it, so the
 * documents that one builds link through this scan.
 */
final class DocumentRouteWarmer
{
    /**
     * @param bool $enabled `jsonapi.openapi.enabled` and `jsonapi.openapi.describedby` on, and no `document_url` configured
     * @param bool $exposed the expose gate: `app.debug` or `jsonapi.openapi.expose_in_prod`
     */
    public function __construct(
        private readonly DocumentRouteLocator $locator,
        private readonly ArtifactStore $store,
        private readonly bool $enabled = true,
        private readonly bool $exposed = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Scans and stores the document routes, returning the warnings it logged.
     *
     * @return list<string>
     */
    public function warm(): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $routes = $this->locator->rescan();
            $this->store->writeDocumentRoutes($routes);
        } catch (\Throwable $e) {
            $message = \sprintf('Failed to find the OpenAPI document routes: %s', $e->getMessage());
            $this->logger?->error($message, ['exception' => $e]);

            return [$message];
        }

        if (!$this->exposed) {
            return [];
        }

        $mounted = $this->locator->mountedServers();
        $warnings = [];
        foreach ($routes as $server => $route) {
            if ($route !== null) {
                continue;
            }

            $inUse = $server === DocumentFactory::COMBINED_KEY ? $mounted !== [] : \in_array($server, $mounted, true);
            if (!$inUse) {
                continue;
            }

            $warnings[] = $server === DocumentFactory::COMBINED_KEY
                ? \sprintf('No route serves the combined OpenAPI document, so links carry no describedby. Set jsonapi.openapi.document_url, or register the document route: let the package register its routes, or add a named route to %s.', OpenApiController::class)
                : \sprintf('No route serves the OpenAPI document for server "%1$s", so its links carry no describedby. Set jsonapi.openapi.document_url, or register the document route: let the package register its routes, or add a named route to %2$s with a "server" default of "%1$s".', $server, OpenApiController::class);
        }

        foreach ($warnings as $warning) {
            $this->logger?->warning($warning);
        }

        return $warnings;
    }
}
