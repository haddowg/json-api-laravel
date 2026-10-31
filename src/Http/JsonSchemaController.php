<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaServeDecoratorInterface;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiLaravel\OpenApi\Serve\ConditionalResponder;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
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
 *
 * ETags, conditional requests, `Cache-Control` and {@see JsonSchemaServeDecoratorInterface}
 * work as they do for the OpenAPI document. The schemas are standalone
 * documents with no shared components, so nothing is pruned after decoration.
 */
final class JsonSchemaController
{
    /** @var list<JsonSchemaServeDecoratorInterface> */
    private readonly array $decorators;

    private readonly bool $keyed;

    private readonly ConditionalResponder $responder;

    /**
     * @param iterable<JsonSchemaServeDecoratorInterface> $serveDecorators the tagged serve decorators, in the order they run
     * @param ?string                                     $cacheControl    `jsonapi.openapi.cache_control`
     */
    public function __construct(
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
    ) {
        $this->decorators = \is_array($serveDecorators) ? \array_values($serveDecorators) : \iterator_to_array($serveDecorators, false);
        $this->keyed = ConditionalResponder::allKeyed($this->decorators);
        $this->responder = new ConditionalResponder($cacheControl);
    }

    /**
     * Serves the aggregate for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined aggregate in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        $combined = $this->combined();
        $key = $combined ? DocumentFactory::COMBINED_KEY : ($server ?? ServerRegistry::DEFAULT_SERVER);

        $json = null;
        if ($this->decorators === []) {
            $response = $this->responder->prepare($this->artifactHash($key, $combined, $json));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($json ?? $this->store->readSchemaAggregate($key) ?? $this->build($key, $combined));
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $response = $this->responder->prepare(ConditionalResponder::keyedHash($this->artifactHash($key, $combined, $json), $decorators, $request, $key));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        $json ??= $this->store->readSchemaAggregate($key) ?? $this->build($key, $combined);
        $decoded = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, \stdClass> $schemas */
        $schemas = $decoded instanceof \stdClass ? \get_object_vars($decoded) : [];
        foreach ($this->decorators as $decorator) {
            $schemas = $decorator->decorate($schemas, $request, $key);
        }
        // Cast to an object so an empty aggregate renders `{}`, never `[]`.
        $body = (string) \json_encode((object) $schemas, ConditionalResponder::JSON_FLAGS);

        if ($response === null) {
            $response = $this->responder->prepare(EntityTag::hash($body));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($body);
    }

    /**
     * The stored hash of the aggregate, or the hash of its bytes (handing them back through
     * `$json`) when no hash was stored.
     */
    private function artifactHash(string $key, bool $combined, ?string &$json): string
    {
        $hash = $this->store->readSchemaAggregateHash($key);
        if ($hash !== null) {
            return $hash;
        }

        $json = $this->store->readSchemaAggregate($key) ?? $this->build($key, $combined);

        return EntityTag::hash($json);
    }

    private function build(string $key, bool $combined): string
    {
        $documents = $combined ? $this->schemas->combined() : $this->schemas->forServer($key);
        // Cast to an object so an empty server renders `{}`, never `[]`.
        $json = (string) \json_encode((object) $documents, ConditionalResponder::JSON_FLAGS);

        if ((bool) config('app.debug', false)) {
            $this->store->writeSchemaAggregateWithHash($key, $json);
        }

        return $json;
    }

    private function combined(): bool
    {
        return config('jsonapi.openapi.multi_server') === 'combined';
    }
}
