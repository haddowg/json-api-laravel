<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiUiRenderer;
use Illuminate\Http\Response;

/**
 * Serves the documentation viewer page (PLAN decision 11) — a single config-driven
 * route at `jsonapi.openapi.ui.path` (default `/docs`) that renders **Swagger UI _or_
 * ReDoc** (per `jsonapi.openapi.ui.renderer`, one not both) pointed at the OpenAPI
 * document the {@see OpenApiController} serves.
 *
 * The page is a **plain HTML string** (no Blade), so the viewer adds zero
 * dependencies. It is **CDN-linked**: it loads the renderer's assets from a pinned
 * public CDN by default, and `jsonapi.openapi.ui.cdn` swaps that origin for a
 * self-hosted/air-gapped mirror. The spec URL it targets is resolved from the docs.json
 * route via the router (so it honours any routing prefix / sub-path mount), falling back
 * to the configured json path only when that route is absent.
 *
 * The route is registered only when `ui.enabled` is true **and** the expose gate passes
 * (`app.debug` or `expose_in_prod`), so this controller never re-checks exposure. The
 * page carries no JSON:API route marker, so the package's exception renderer does not
 * own its errors.
 */
final class OpenApiUiController
{
    /**
     * The pinned Swagger UI CDN version — the asset base when no `ui.cdn` override is
     * configured. Bumping it is the single edit to track Swagger UI releases.
     */
    public const string SWAGGER_UI_VERSION = '5.17.14';

    /**
     * The pinned ReDoc CDN version (the standalone bundle), used the same way.
     */
    public const string REDOC_VERSION = '2.1.5';

    /**
     * The route name of the default-server / combined OpenAPI document, generated to
     * produce the spec URL the page fetches.
     */
    public const string DOCUMENT_ROUTE = 'jsonapi.openapi.json';

    private const SWAGGER_CDN = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_UI_VERSION;

    private const REDOC_CDN = 'https://cdn.jsdelivr.net/npm/redoc@' . self::REDOC_VERSION . '/bundles';

    public function __invoke(): Response
    {
        $specUrl = $this->specUrl();

        $html = $this->renderer() === OpenApiUiRenderer::Redoc
            ? $this->redocPage($specUrl)
            : $this->swaggerPage($specUrl);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The spec URL the page fetches — generated from the docs.json route so it honours
     * any routing prefix. Falls back to the configured json path only when that route is
     * not registered (e.g. an app that suppressed the docs routes).
     */
    private function specUrl(): string
    {
        $router = app('router');
        if ($router instanceof \Illuminate\Routing\Router && $router->has(self::DOCUMENT_ROUTE)) {
            return route(self::DOCUMENT_ROUTE);
        }

        $path = config('jsonapi.openapi.json.path');
        $path = \is_string($path) && $path !== '' ? $path : '/docs.json';

        return url('/' . \ltrim($path, '/'));
    }

    private function renderer(): OpenApiUiRenderer
    {
        $renderer = config('jsonapi.openapi.ui.renderer');

        return \is_string($renderer) ? (OpenApiUiRenderer::tryFrom($renderer) ?? OpenApiUiRenderer::Swagger) : OpenApiUiRenderer::Swagger;
    }

    private function assetBase(string $default): string
    {
        $cdn = config('jsonapi.openapi.ui.cdn');

        return \rtrim(\is_string($cdn) && $cdn !== '' ? $cdn : $default, '/');
    }

    private function swaggerPage(string $specUrl): string
    {
        $base = $this->assetBase(self::SWAGGER_CDN);
        $css = $this->attr($base . '/swagger-ui.css');
        $js = $this->attr($base . '/swagger-ui-bundle.js');
        $preset = $this->attr($base . '/swagger-ui-standalone-preset.js');
        $spec = $this->json($specUrl);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>API documentation</title>
              <link rel="stylesheet" href="{$css}">
            </head>
            <body>
              <div id="swagger-ui"></div>
              <script src="{$js}" crossorigin></script>
              <script src="{$preset}" crossorigin></script>
              <script>
                window.addEventListener('load', function () {
                  window.ui = SwaggerUIBundle({
                    url: {$spec},
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                    layout: 'StandaloneLayout'
                  });
                });
              </script>
            </body>
            </html>
            HTML;
    }

    private function redocPage(string $specUrl): string
    {
        $base = $this->assetBase(self::REDOC_CDN);
        $js = $this->attr($base . '/redoc.standalone.js');
        $spec = $this->attr($specUrl);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>API documentation</title>
              <style>body { margin: 0; padding: 0; }</style>
            </head>
            <body>
              <redoc spec-url="{$spec}"></redoc>
              <script src="{$js}" crossorigin></script>
            </body>
            </html>
            HTML;
    }

    /**
     * HTML-attribute-encode a value (URLs are config / route-derived, never user input,
     * but escaping keeps the markup well-formed regardless).
     */
    private function attr(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /**
     * JSON-encode a value for safe inline embedding in a `<script>` (escapes the closing
     * tag sequence so a path can never break out of the script context).
     */
    private function json(string $value): string
    {
        return (string) \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES);
    }
}
