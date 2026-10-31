<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use Illuminate\Http\Request;

/**
 * Adjusts the served `schemas.json` aggregate for one request, the per-type
 * JSON Schema counterpart of {@see OpenApiServeDecoratorInterface}.
 *
 * Bind the class and tag it with
 * {@see \haddowg\JsonApiLaravel\JsonApiServiceProvider::JSON_SCHEMA_SERVE_DECORATOR_TAG};
 * decorators run in registration order. The `jsonapi:jsonschema:export` command never
 * runs it. Implement {@see OpenApiServeCacheKeyInterface} as well to answer conditional
 * requests without decorating.
 */
interface JsonSchemaServeDecoratorInterface
{
    /**
     * @param array<string, \stdClass> $schemas the standalone JSON Schema documents keyed by
     *                                          JSON:API type; drop a key to hide that type
     * @param string                   $server  the server name, or
     *                                          {@see DocumentFactory::COMBINED_KEY} in
     *                                          combined mode
     *
     * @return array<string, \stdClass>
     */
    public function decorate(array $schemas, Request $request, string $server): array;
}
