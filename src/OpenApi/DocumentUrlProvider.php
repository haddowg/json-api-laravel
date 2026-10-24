<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApiLaravel\Http\OpenApiUiController;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The URL a server's `describedby` links point into: its OpenAPI document (ADR 0037).
 *
 * A configured `jsonapi.openapi.document_url` is used as given, with `{server}` replaced by
 * the server name (`combined` in combined mode), so a document published elsewhere can be
 * named absolutely. Otherwise it is the package's own document route for the server,
 * host-relative and including any prefix and base path the route is served under. Null
 * when `describedby` or generation is off, or when the document route isn't registered
 * and no URL is configured, so no link points at a document nobody serves.
 */
final readonly class DocumentUrlProvider
{
    public const string SERVER_PLACEHOLDER = '{server}';

    /**
     * @param bool    $enabled       `jsonapi.openapi.enabled` and `jsonapi.openapi.describedby` both on
     * @param bool    $combined      `jsonapi.openapi.multi_server === combined`, where every server shares one document
     * @param ?string $configuredUrl `jsonapi.openapi.document_url`
     */
    public function __construct(
        private UrlGenerator $urls,
        private bool $enabled = true,
        private bool $combined = false,
        private ?string $configuredUrl = null,
    ) {}

    public function forServer(?string $server = null): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $server ??= ServerRegistry::DEFAULT_SERVER;

        if ($this->configuredUrl !== null) {
            return \str_replace(self::SERVER_PLACEHOLDER, $this->combined ? 'combined' : $server, $this->configuredUrl);
        }

        $route = !$this->combined && $server !== ServerRegistry::DEFAULT_SERVER
            ? \sprintf('jsonapi.%s.openapi.json', $server)
            : OpenApiUiController::DOCUMENT_ROUTE;

        try {
            $url = $this->urls->route($route, [], true);
        } catch (RouteNotFoundException | UrlGenerationException) {
            return null;
        }

        return self::hostRelative($url, $this->urls->to('/'));
    }

    /**
     * `$url` without its scheme and host when it is on the same host as `$root`. Laravel's
     * own relative URLs drop the base path an app below the web root is served under, so
     * the absolute URL is trimmed instead.
     */
    private static function hostRelative(string $url, string $root): string
    {
        $parts = \parse_url($url);
        $rootParts = \parse_url($root);
        if (!\is_array($parts) || !\is_array($rootParts)) {
            return $url;
        }

        $authority = static fn(array $p): string => \strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
        if (!isset($parts['host']) || $authority($parts) !== $authority($rootParts)) {
            return $url;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
