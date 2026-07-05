<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route-scoped "after" middleware that emits the declarative response headers a
 * JSON:API type declares (PLAN decision 12, bundle ADR 0054) — the Laravel twin of
 * the bundle's `kernel.response` `ResponseHeadersListener`. Laravel has no
 * `kernel.response`, and its exception path renders errors separately, so the seam
 * is an after-middleware on the JSON:API route group: it runs the controller
 * (`$next($request)`), then sees the resolved response — **both** a success and an
 * error-rendered document (Laravel's routing pipeline converts a thrown exception to
 * a response at the throwing stage, which propagates back through this middleware).
 *
 *  - **deprecation + sunset** — the resolved {@see DeprecationHeaders} on **every**
 *    response for the type (reads and writes alike — a deprecated endpoint is
 *    deprecated regardless of method).
 *  - **HTTP cache headers** — the resolved {@see CacheHeaders} (resource-level +
 *    per-operation override, over the global default) on a **safe (`GET`) successful**
 *    read only; a write or an error document never gets a `Cache-Control`.
 *
 * It reads the type + read shape off the matched route via the {@see TargetResolver}
 * (the atomic route carries no `_jsonapi_type`, so it is skipped) and **never
 * clobbers a header an app set explicitly** (each value object checks before writing;
 * an app-configured `Cache-Control` is detected and left untouched).
 */
final class ResponseHeadersMiddleware
{
    public function __construct(
        private readonly ResponseHeadersRegistry $registry,
        private readonly TargetResolver $targetResolver,
    ) {}

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        $target = $this->targetResolver->resolveFromRequest($request);
        if (!$target instanceof Target) {
            return $response;
        }

        $type = $target->type;

        // Deprecation/Sunset are emitted on every response for the type, reads and
        // writes alike (each header is only written when the app has not set it).
        $this->registry->deprecationFor($type)?->applyTo($response);

        // Cache headers apply only to a safe (GET) successful read — never a write
        // verb and never an error document (2xx only).
        if (!$request->isMethodCacheable() || !$response->isSuccessful()) {
            return $response;
        }

        // An app that configured caching itself keeps it untouched: only apply when
        // the response carries no meaningful Cache-Control (a bare Response computes
        // the conservative `no-cache, private` default, so the absence of a real
        // directive is detected by that computed value).
        if ($this->hasExplicitCacheControl($response)) {
            return $response;
        }

        $this->registry->cacheFor($type, ResponseHeaderOperation::fromTarget($target))?->applyTo($response);

        return $response;
    }

    /**
     * Whether the response already carries explicit caching the app configured — any
     * real `Cache-Control` directive beyond the conservative `no-cache, private` /
     * `private, must-revalidate` default a bare Response computes, or an explicit
     * `Expires`/`Last-Modified` freshness signal.
     */
    private function hasExplicitCacheControl(Response $response): bool
    {
        if ($response->headers->has('Expires') || $response->headers->has('Last-Modified')) {
            return true;
        }

        $value = $response->headers->get('Cache-Control', '');

        return $value !== '' && $value !== 'no-cache, private';
    }
}
