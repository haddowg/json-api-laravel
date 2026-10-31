<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\UnserializableValue;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeDecoratorInterface;
use haddowg\JsonApiLaravel\OpenApi\Serve\ConditionalResponder;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the OpenAPI 3.1 document for a server (PLAN decision 11):
 * `GET {json.path}` (default `/docs.json`) for the implicit `default` server and
 * `GET /{server}/docs.json` for a named one.
 *
 * It serves the **pre-built artifact** the {@see DocumentWarmer} wrote at
 * `artisan optimize` (an `O(file read)`, never a per-request build). When the artifact
 * is absent — local dev (no warmup), or a deploy where the optional warmer was
 * skipped/failed — it **lazy-builds** via the {@see DocumentFactory}, and (with
 * `app.debug` on) caches the result so the next request is served from disk. Lazy
 * writes are skipped outside debug so a read-only prod filesystem is never written to.
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
 *
 * With no {@see OpenApiServeDecoratorInterface} registered the artifact's bytes are served
 * as they are, tagged with the hash the warmer stored beside them, so a matching
 * `If-None-Match` costs one small read. With serve decorators it loads the warmed value
 * objects, runs the decorators with the request, prunes what they orphaned and serialises
 * the result.
 */
final class OpenApiController
{
    /** @var list<OpenApiServeDecoratorInterface> */
    private readonly array $decorators;

    private readonly bool $keyed;

    private readonly ConditionalResponder $responder;

    /**
     * @param iterable<OpenApiServeDecoratorInterface> $serveDecorators the tagged serve decorators, in the order they run
     * @param ?string                                  $cacheControl    `jsonapi.openapi.cache_control`
     */
    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly ArtifactStore $store,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
    ) {
        $this->decorators = \is_array($serveDecorators) ? \array_values($serveDecorators) : \iterator_to_array($serveDecorators, false);
        $this->keyed = ConditionalResponder::allKeyed($this->decorators);
        $this->responder = new ConditionalResponder($cacheControl);
    }

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        $combined = $this->combined();
        $key = $combined ? DocumentFactory::COMBINED_KEY : ($server ?? ServerRegistry::DEFAULT_SERVER);

        // A missing artifact is built at most once per request.
        $built = null;
        $build = function () use (&$built, $key, $combined): array {
            return $built ??= $this->build($key, $combined);
        };

        if ($this->decorators === []) {
            $json = null;
            $response = $this->responder->prepare($this->artifactHash($key, $json, $build));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($json ?? $this->store->read($key) ?? $build()[0]);
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $json = null;
            $response = $this->responder->prepare(ConditionalResponder::keyedHash($this->artifactHash($key, $json, $build), $decorators, $request, $key));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        $artifact = $built[1] ?? $this->store->readServeArtifact($key) ?? $build()[1];
        $document = $artifact->document;
        foreach ($this->decorators as $decorator) {
            $document = $decorator->decorate($document, $request, $key);
        }
        $body = (string) \json_encode($artifact->prune($document), ConditionalResponder::JSON_FLAGS);

        if ($response === null) {
            $response = $this->responder->prepare(EntityTag::hash($body));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($body);
    }

    /**
     * The stored hash of the artifact, or the hash of its bytes (handing them back through
     * `$json`) when no hash was stored.
     *
     * @param \Closure(): array{0: string, 1: ServeArtifact} $build
     */
    private function artifactHash(string $key, ?string &$json, \Closure $build): string
    {
        $hash = $this->store->readHash($key);
        if ($hash !== null) {
            return $hash;
        }

        $json = $this->store->read($key) ?? $build()[0];

        return EntityTag::hash($json);
    }

    /**
     * @return array{0: string, 1: ServeArtifact}
     */
    private function build(string $key, bool $combined): array
    {
        $document = $combined ? $this->documents->combined() : $this->documents->forServer($key);
        $json = $document->toJsonString(true);
        $artifact = ServeArtifact::of($document);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ((bool) config('app.debug', false)) {
            $this->store->writeWithHash($key, $json);
            try {
                $this->store->writeServeArtifact($key, $artifact);
            } catch (UnserializableValue) {
                // A value the codec can't carry only costs later requests this build.
            }
        }

        return [$json, $artifact];
    }

    private function combined(): bool
    {
        return config('jsonapi.openapi.multi_server') === 'combined';
    }
}
