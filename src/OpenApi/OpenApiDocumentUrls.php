<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApiLaravel\Http\OpenApiUiController;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The absolute URLs a server's OpenAPI document is served at, on the host of a given
 * request. The built-in viewer uses it for Redoc's `downloadUrls`, and an app rendering its
 * own viewer page can resolve it from the container the same way.
 *
 * Each URL comes from the package's named route for the server, else from an app route to
 * the format's controller ({@see DocumentRouteLocator}). The JSON URL falls back to the
 * path the package would register. The YAML URL is null when `jsonapi.openapi.yaml.enabled`
 * resolves to false or no route serves it. In combined mode every server shares the one
 * document.
 */
final readonly class OpenApiDocumentUrls
{
    /**
     * @param bool   $combined    `jsonapi.openapi.multi_server === combined`
     * @param bool   $yamlEnabled `jsonapi.openapi.yaml.enabled`, resolved
     * @param string $jsonPath    `jsonapi.openapi.json.path`
     */
    public function __construct(
        private UrlGenerator $urls,
        private bool $combined = false,
        private bool $yamlEnabled = false,
        private string $jsonPath = '/docs.json',
        private ?DocumentRouteLocator $jsonRoutes = null,
        private ?DocumentRouteLocator $yamlRoutes = null,
    ) {}

    public function forRequest(Request $request, ?string $server = null): DocumentUrls
    {
        $server ??= ServerRegistry::DEFAULT_SERVER;
        $default = $this->combined || $server === ServerRegistry::DEFAULT_SERVER;

        $json = $this->route($default ? OpenApiUiController::DOCUMENT_ROUTE : \sprintf('jsonapi.%s.openapi.json', $server), $server, $this->jsonRoutes)
            ?? $this->urls->to($default ? $this->jsonPath : '/' . $server . '/docs.json');

        $yaml = $this->yamlEnabled
            ? $this->route($default ? OpenApiUiController::YAML_DOCUMENT_ROUTE : \sprintf('jsonapi.%s.openapi.yaml', $server), $server, $this->yamlRoutes)
            : null;

        $root = \rtrim($this->urls->to('/'), '/');

        return new DocumentUrls(
            self::onRequestRoot($json, $root, $request),
            $yaml === null ? null : self::onRequestRoot($yaml, $root, $request),
        );
    }

    private function route(string $name, string $server, ?DocumentRouteLocator $locator): ?string
    {
        $url = $this->generate($name, []);
        if ($url !== null) {
            return $url;
        }

        $found = $locator?->find($server);

        return $found === null ? null : $this->generate($found['route'], $found['parameters']);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function generate(string $name, array $parameters): ?string
    {
        try {
            return $this->urls->route($name, $parameters, true);
        } catch (RouteNotFoundException | UrlGenerationException) {
            return null;
        }
    }

    /**
     * `$url` moved from the URL generator's root onto the request's, so a request for
     * another host or base path gets URLs on its own. A URL on another host, such as a
     * route with its own domain, is left as it is.
     */
    private static function onRequestRoot(string $url, string $root, Request $request): string
    {
        if ($root === '' || ($url !== $root && !\str_starts_with($url, $root . '/'))) {
            return $url;
        }

        return \rtrim($request->root(), '/') . \substr($url, \strlen($root));
    }
}
