<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;
use Illuminate\Http\Request;

/**
 * Adjusts the served OpenAPI document for one request.
 *
 * {@see OpenApiFactoryInterface} runs when the document is built, once per
 * `artisan optimize`. This interface runs when `docs.json` is served, with the request in
 * hand, so a document can differ per host: absolute `servers` URLs, operations hidden
 * from a tenant that lacks a feature, example hosts rewritten. The CLI export and the
 * static `public_path` files never run it.
 *
 * Bind the class and tag it with
 * {@see \haddowg\JsonApiLaravel\JsonApiServiceProvider::OPENAPI_SERVE_DECORATOR_TAG}.
 * Decorators run in registration order, so the last one registered gets the last word,
 * as with the build-time tag. After the last decorator the package drops components,
 * path items and tag definitions that the decorators left unreferenced, so a decorator
 * only removes operations.
 *
 * Implement {@see OpenApiServeCacheKeyInterface} as well so a conditional request can be
 * answered with a 304 before the document is loaded or decorated.
 */
interface OpenApiServeDecoratorInterface
{
    /**
     * @param OpenApi $document the warmed document (after the build-time decorators), or the
     *                          result of an earlier serve decorator
     * @param string  $server   the server name, or {@see DocumentFactory::COMBINED_KEY} in
     *                          combined mode
     */
    public function decorate(OpenApi $document, Request $request, string $server): OpenApi;
}
