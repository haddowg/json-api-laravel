<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The ETag, `If-None-Match` and `Cache-Control` handling shared by the `docs.json` and
 * `schemas.json` controllers (ADR 0036). The tags themselves and the match come from
 * core's {@see EntityTag}; this maps them onto Laravel's request and response.
 *
 * @internal
 */
final class ConditionalResponder
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT;

    public function __construct(private readonly ?string $cacheControl = null) {}

    /**
     * Whether every decorator supplies a cache key. A partial key would let two different
     * bodies share an ETag, so anything short of all of them falls back to the body hash.
     *
     * @param list<object> $decorators
     */
    public static function allKeyed(array $decorators): bool
    {
        foreach ($decorators as $decorator) {
            if (!$decorator instanceof OpenApiServeCacheKeyInterface) {
                return false;
            }
        }

        return $decorators !== [];
    }

    /**
     * The ETag hash of a decorated response, from the artifact hash and every decorator's
     * cache key in the order the decorators run.
     *
     * @param list<OpenApiServeCacheKeyInterface> $decorators
     */
    public static function keyedHash(string $artifactHash, array $decorators, Request $request, string $server): string
    {
        $keys = [];
        foreach ($decorators as $decorator) {
            $keys[] = [$decorator::class, $decorator->cacheKey($request, $server)];
        }

        return EntityTag::keyed($artifactHash, $keys);
    }

    /**
     * A JSON response carrying the ETag and the configured `Cache-Control`, still without
     * a body.
     */
    public function prepare(string $hash): Response
    {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->setEtag($hash);
        if ($this->cacheControl !== null) {
            $response->headers->set('Cache-Control', $this->cacheControl);
        }

        return $response;
    }

    /**
     * Whether `If-None-Match` matches the response's ETag, turning the response into a 304
     * when it does. The match is weak, so a CDN's `W/` copy of the tag still matches.
     */
    public function notModified(Response $response, Request $request): bool
    {
        $etag = $response->getEtag();
        $ifNoneMatch = $request->headers->all('if-none-match');
        if (!$request->isMethodCacheable() || $etag === null || $ifNoneMatch === []) {
            return false;
        }

        if (!EntityTag::matches($etag, \implode(', ', $ifNoneMatch))) {
            return false;
        }

        $response->setNotModified();

        return true;
    }
}
