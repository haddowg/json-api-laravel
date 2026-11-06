<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\UnserializableValue;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeDecoratorInterface;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Builds the response for a server's OpenAPI document in one {@see DocumentFormat}, for
 * the JSON and YAML controllers alike.
 *
 * It serves the artifact the {@see \haddowg\JsonApiLaravel\OpenApi\DocumentWarmer} wrote
 * at `artisan optimize`. When the artifact is absent it lazy-builds through the
 * {@see DocumentFactory}, and with `app.debug` on caches the result so the next request
 * reads it from disk. Lazy writes are skipped outside debug so a read-only prod
 * filesystem is never written to.
 *
 * With no serve decorator the artifact's bytes are served as they are, tagged with the
 * hash the warmer stored beside them, so a matching `If-None-Match` costs one small read.
 * With serve decorators it loads the warmed value objects, runs the decorators with the
 * request, prunes what they orphaned and serialises the result. Either way the JSON body
 * is the source every format encodes, and each format folds its name into the ETag.
 *
 * @internal
 */
final class DocumentResponder
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
     * The document for `$server` (the implicit `default` server when null), or the single
     * combined document in combined mode, in `$format`.
     */
    public function respond(Request $request, ?string $server, DocumentFormat $format): Response
    {
        $combined = config('jsonapi.openapi.multi_server') === 'combined';
        $key = $combined ? DocumentFactory::COMBINED_KEY : ($server ?? ServerRegistry::DEFAULT_SERVER);

        // A missing artifact is built at most once per request.
        $built = null;
        $build = function () use (&$built, $key, $combined): array {
            return $built ??= $this->build($key, $combined);
        };

        if ($this->decorators === []) {
            $json = null;
            $response = $this->responder->prepare($format->tag($this->artifactHash($key, $json, $build)), $format->contentType());
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($format->encode($json ?? $this->store->read($key) ?? $build()[0]));
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $json = null;
            $hash = ConditionalResponder::keyedHash($this->artifactHash($key, $json, $build), $decorators, $request, $key);
            $response = $this->responder->prepare($format->tag($hash), $format->contentType());
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
            $response = $this->responder->prepare($format->tag(EntityTag::hash($body)), $format->contentType());
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($format->encode($body));
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
}
