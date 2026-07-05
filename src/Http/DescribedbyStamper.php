<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Stamps a top-level `links.describedby` onto every JSON:API response, pointing at the
 * served OpenAPI document for the request's server (JSON:API 1.1 — a link to a description
 * document). The Laravel twin of the Symfony bundle's `DescribedbyListener` (bundle ADR
 * 0105): the link is generated against the request host by the router, so it is correct
 * behind any prefix/host the application mounted the document routes under, and points at the
 * default server's document — or, in per-server (`separate`) mode, the current server's
 * document.
 *
 * When the document routes are not registered (generation disabled, or the expose gate
 * closed) URL generation fails and no link is added — so the member appears only when the
 * document is actually reachable. Disabled wholesale by `jsonapi.openapi.describedby: false`.
 *
 * An author-set `describedby` already on the response wins (core's
 * {@see AbstractResponse::withDescribedby()} only fills an empty slot at render time).
 */
final readonly class DescribedbyStamper
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private bool $enabled,
        private bool $combined,
    ) {}

    /**
     * Returns `$response` with `links.describedby` set to the served document's URL for
     * `$serverName`, or unchanged when disabled or the document is unreachable.
     *
     * @template T of AbstractResponse
     *
     * @param T $response
     *
     * @return T
     */
    public function stamp(AbstractResponse $response, string $serverName): AbstractResponse
    {
        if (!$this->enabled) {
            return $response;
        }

        $url = $this->documentUrl($serverName);
        if ($url === null) {
            return $response;
        }

        return $response->withDescribedby(new Link($url));
    }

    /**
     * The absolute URL of the OpenAPI document for `$serverName`, or `null` when its route
     * is not registered (so no `describedby` is stamped).
     */
    private function documentUrl(string $serverName): ?string
    {
        try {
            // In per-server mode a named (non-default) server has its own document route; the
            // default server and combined mode both serve the single default document.
            $name = !$this->combined && $serverName !== '' && $serverName !== ServerRegistry::DEFAULT_SERVER
                ? \sprintf('jsonapi.%s.openapi.json', $serverName)
                : OpenApiUiController::DOCUMENT_ROUTE;

            return $this->urlGenerator->route($name, [], true);
        } catch (RouteNotFoundException | UrlGenerationException) {
            return null;
        }
    }
}
