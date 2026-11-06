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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a server's OpenAPI 3.1 document as YAML, `application/yaml` per RFC 9512:
 * `GET {yaml.path}` (default `/docs.yaml`) for the implicit `default` server and
 * `GET /{server}/docs.yaml` for a named one.
 *
 * The body is the {@see OpenApiController}'s JSON document, serve decorators, pruning and
 * all, re-encoded as YAML, under an ETag of its own. An app can route to it itself, with a
 * `server` default naming a server, the same way it can route to the JSON controller. It
 * answers 404 when `jsonapi.openapi.yaml.enabled` resolves to false, which it does by
 * default without symfony/yaml.
 */
final class OpenApiYamlController
{
    private readonly DocumentResponder $responder;

    /**
     * @param iterable<OpenApiServeDecoratorInterface> $serveDecorators the tagged serve decorators, in the order they run
     * @param ?string                                  $cacheControl    `jsonapi.openapi.cache_control`
     * @param bool                                     $enabled         `jsonapi.openapi.yaml.enabled`, resolved
     */
    public function __construct(
        DocumentFactory $documents,
        ArtifactStore $store,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
        private readonly bool $enabled = true,
    ) {
        $this->responder = new DocumentResponder($documents, $store, $serveDecorators, $cacheControl);
    }

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('The OpenAPI document is not served as YAML.');
        }

        return $this->responder->respond($request, $server, DocumentFormat::Yaml);
    }
}
