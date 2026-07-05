<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Response;

/**
 * Serves the aggregate JSON Schema document for a server alongside the OpenAPI
 * document: `GET {json_schema.path}` (default `/schemas.json`) for the implicit
 * `default` server and `GET /{server}/schemas.json` for a named one.
 *
 * The body is the **standalone per-type JSON Schema 2020-12 documents** the
 * {@see JsonSchemaFactory} builds (the same schemas the `jsonapi:jsonschema:export`
 * command emits), gathered into one object keyed by JSON:API type — a single fetch a
 * client codegen consumes to drive an opt-in request/response validation seam. It
 * mirrors the {@see OpenApiController}: it serves the **pre-built artifact** the
 * {@see DocumentWarmer} wrote at `artisan optimize`, lazy-building via the factory when
 * the artifact is absent and (in debug only) caching the result.
 *
 * Each schema is JSON Schema, not a JSON:API document, so the aggregate is served as
 * `application/json`. The route is registered only behind the same expose gate as the
 * OpenAPI document plus `jsonapi.openapi.json_schema.enabled`, so the controller need
 * not re-check exposure.
 */
final class JsonSchemaController
{
    public function __construct(
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
    ) {}

    /**
     * Serves the aggregate for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined aggregate in combined mode.
     */
    public function __invoke(?string $server = null): Response
    {
        $combined = $this->combined();
        $key = $combined ? DocumentFactory::COMBINED_KEY : ($server ?? ServerRegistry::DEFAULT_SERVER);

        $json = $this->store->readSchemaAggregate($key) ?? $this->build($key, $combined);

        return new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    private function build(string $key, bool $combined): string
    {
        $documents = $combined ? $this->schemas->combined() : $this->schemas->forServer($key);
        // Cast to an object so an empty server renders `{}`, never `[]`.
        $json = (string) \json_encode((object) $documents, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);

        if ((bool) config('app.debug', false)) {
            $this->store->writeSchemaAggregate($key, $json);
        }

        return $json;
    }

    private function combined(): bool
    {
        return config('jsonapi.openapi.multi_server') === 'combined';
    }
}
