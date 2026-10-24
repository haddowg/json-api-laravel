<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeDecoratorInterface;
use Illuminate\Http\Request;

/**
 * Advertises the requesting host as the only server.
 */
final class HostServersDecorator implements OpenApiServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        return $document->withServers([new Server('https://' . $request->getHost() . '/api')]);
    }

    public function cacheKey(Request $request, string $server): string
    {
        return $request->getHost();
    }
}
