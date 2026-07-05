<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
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
 */
final class OpenApiController
{
    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly ArtifactStore $store,
    ) {}

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(?string $server = null): Response
    {
        $combined = $this->combined();
        $key = $combined ? DocumentFactory::COMBINED_KEY : ($server ?? ServerRegistry::DEFAULT_SERVER);

        $json = $this->store->read($key) ?? $this->build($key, $combined);

        return new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    private function build(string $key, bool $combined): string
    {
        $document = $combined ? $this->documents->combined() : $this->documents->forServer($key);
        $json = $document->toJsonString(true);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ((bool) config('app.debug', false)) {
            $this->store->write($key, $json);
        }

        return $json;
    }

    private function combined(): bool
    {
        return config('jsonapi.openapi.multi_server') === 'combined';
    }
}
