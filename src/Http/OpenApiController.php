<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeDecoratorInterface;
use haddowg\JsonApiLaravel\OpenApi\Serve\DocumentFormat;
use haddowg\JsonApiLaravel\OpenApi\Serve\DocumentResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the OpenAPI 3.1 document for a server:
 * `GET {json.path}` (default `/docs.json`) for the implicit `default` server and
 * `GET /{server}/docs.json` for a named one. {@see OpenApiYamlController} serves the
 * same document as YAML.
 *
 * It serves the **pre-built artifact** the {@see \haddowg\JsonApiLaravel\OpenApi\DocumentWarmer}
 * wrote at `artisan optimize`, lazy-building it when absent, and runs the serve decorators
 * per request ({@see DocumentResponder}).
 *
 * The document is **OpenAPI JSON, not a JSON:API document**, so it is served as
 * `application/json`. These routes carry no JSON:API route marker, so the package's
 * exception renderer does not own their errors.
 *
 * The route is registered only when `app.debug` is true **or**
 * `jsonapi.openapi.expose_in_prod` is true (the registrar's expose gate), so the
 * controller need not re-check exposure. In **combined** multi-server mode the registrar
 * emits only the json-path route, and this controller serves the single combined
 * document spanning every server.
 */
final class OpenApiController
{
    private readonly DocumentResponder $responder;

    /**
     * @param iterable<OpenApiServeDecoratorInterface> $serveDecorators the tagged serve decorators, in the order they run
     * @param ?string                                  $cacheControl    `jsonapi.openapi.cache_control`
     */
    public function __construct(
        DocumentFactory $documents,
        ArtifactStore $store,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
    ) {
        $this->responder = new DocumentResponder($documents, $store, $serveDecorators, $cacheControl);
    }

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        return $this->responder->respond($request, $server, DocumentFormat::Json);
    }
}
