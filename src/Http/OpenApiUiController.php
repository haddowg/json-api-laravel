<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiUiConfig;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiUiRenderer;
use haddowg\JsonApiLaravel\OpenApi\DocumentUrls;
use haddowg\JsonApiLaravel\OpenApi\OpenApiDocumentUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the documentation viewer page — a single config-driven
 * route at `jsonapi.openapi.ui.path` (default `/docs`) that renders **Swagger UI _or_
 * ReDoc** (per `jsonapi.openapi.ui.renderer`, one not both) pointed at the OpenAPI
 * document the {@see OpenApiController} serves.
 *
 * The page is a **plain HTML string** (no Blade), so the viewer adds zero
 * dependencies. It is **CDN-linked**: it loads the renderer's assets from a pinned
 * public CDN by default, and `jsonapi.openapi.ui.cdn` swaps that origin for a
 * self-hosted/air-gapped mirror. The spec URL it targets comes from
 * {@see OpenApiDocumentUrls}: the docs.json route on the request's host (so it honours
 * any routing prefix / sub-path mount), falling back to the configured json path only
 * when that route is absent.
 *
 * The route is registered only when `ui.enabled` is true **and** the expose gate passes
 * (`app.debug` or `expose_in_prod`), so this controller never re-checks exposure. The
 * page carries no JSON:API route marker, so the package's exception renderer does not
 * own its errors.
 *
 * ReDoc defaults to the pinned 3.x release. Its standalone bundle is an ES module that
 * initialises itself from the `<redoc spec-url>` tag, so the page loads it with
 * `<script type="module">` and renders `disable-telemetry="true"` on the tag unless
 * `ui.redoc_telemetry` opts in. `ui.redoc_version` set to an exact 2.x release switches to
 * the classic global-script loader, which has no telemetry.
 *
 * Both Redoc lines get a `downloadUrls` option listing the JSON document and, when it is
 * served, the YAML one. Redoc 3.x JSON-parses it from the tag's `download-urls`
 * attribute. Redoc 2.x reads tag attributes only as strings, so the 2.x page leaves
 * `spec-url` off the tag and calls `Redoc.init()` with the options itself.
 */
final class OpenApiUiController
{
    /**
     * The pinned Swagger UI CDN version — the asset base when no `ui.cdn` override is
     * configured. Bumping it is the single edit to track Swagger UI releases.
     */
    public const string SWAGGER_UI_VERSION = '5.17.14';

    /**
     * The default ReDoc version (the standalone ES module bundle), used when
     * `jsonapi.openapi.ui.redoc_version` is not set.
     */
    public const string REDOC_VERSION = '3.0.1';

    /**
     * The last 2.x ReDoc release the package was checked against, for an app that sets
     * `jsonapi.openapi.ui.redoc_version` back to the 2.x line.
     */
    public const string REDOC_VERSION_2 = '2.5.4';

    /**
     * The route name of the default-server / combined OpenAPI document, generated to
     * produce the spec URL the page fetches.
     */
    public const string DOCUMENT_ROUTE = 'jsonapi.openapi.json';

    /**
     * The route name of the default-server / combined OpenAPI document as YAML.
     */
    public const string YAML_DOCUMENT_ROUTE = 'jsonapi.openapi.yaml';

    private const SWAGGER_CDN = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_UI_VERSION;

    public function __invoke(Request $request): Response
    {
        $urls = app(OpenApiDocumentUrls::class)->forRequest($request);

        $html = $this->renderer() === OpenApiUiRenderer::Redoc
            ? $this->redocPage($urls)
            : $this->swaggerPage($urls->json);

        $response = new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);

        $cacheControl = config('jsonapi.openapi.cache_control');
        if (\is_string($cacheControl) && $cacheControl !== '') {
            $response->headers->set('Cache-Control', $cacheControl);
        }

        return $response;
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

    private function redocPage(DocumentUrls $urls): string
    {
        $version = OpenApiUiConfig::redocVersion(config('jsonapi.openapi.ui.redoc_version'));
        $base = $this->assetBase('https://cdn.jsdelivr.net/npm/redoc@' . $version . '/bundles');
        $js = $this->attr($base . '/redoc.standalone.js');

        if (\str_starts_with($version, '2.')) {
            return $this->redoc2Page($js, $urls);
        }

        $spec = $this->attr($urls->json);
        $downloads = $this->attr((string) \json_encode($urls->downloadUrls(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $telemetry = config('jsonapi.openapi.ui.redoc_telemetry') === true ? '' : ' disable-telemetry="true"';

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
              <redoc spec-url="{$spec}" download-urls="{$downloads}"{$telemetry}></redoc>
              <script type="module" src="{$js}" crossorigin></script>
            </body>
            </html>
            HTML;
    }

    private function redoc2Page(string $js, DocumentUrls $urls): string
    {
        $spec = $this->json($urls->json);
        $options = $this->json(['downloadUrls' => $urls->downloadUrls()]);

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
              <redoc></redoc>
              <script src="{$js}" crossorigin></script>
              <script>
                Redoc.init({$spec}, {$options}, document.querySelector('redoc'));
              </script>
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
    private function json(mixed $value): string
    {
        return (string) \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES);
    }
}
