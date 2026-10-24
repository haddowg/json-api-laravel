<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeDecoratorInterface;
use Illuminate\Http\Request;

/**
 * A serve decorator with no cache key, which switches the ETag to the body hash.
 */
final class UnkeyedDecorator implements OpenApiServeDecoratorInterface
{
    public static int $decorated = 0;

    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        ++self::$decorated;

        return $document->withInfo($document->info->withDescription('Served to ' . $request->getHost()));
    }
}
