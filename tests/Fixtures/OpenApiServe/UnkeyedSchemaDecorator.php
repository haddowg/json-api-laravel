<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApiLaravel\OpenApi\JsonSchemaServeDecoratorInterface;
use Illuminate\Http\Request;

/**
 * A schemas decorator with no cache key, which switches the ETag to the body hash.
 */
final class UnkeyedSchemaDecorator implements JsonSchemaServeDecoratorInterface
{
    public static int $decorated = 0;

    public function decorate(array $schemas, Request $request, string $server): array
    {
        ++self::$decorated;
        foreach ($schemas as $schema) {
            $schema->{'x-served-to'} = $request->getHost();
        }

        return $schemas;
    }
}
