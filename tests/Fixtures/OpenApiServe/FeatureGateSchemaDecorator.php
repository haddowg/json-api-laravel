<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApiLaravel\OpenApi\JsonSchemaServeDecoratorInterface;
use haddowg\JsonApiLaravel\OpenApi\OpenApiServeCacheKeyInterface;
use Illuminate\Http\Request;

/**
 * Hides the `genres` schema from a tenant without the `genres` feature.
 */
final class FeatureGateSchemaDecorator implements JsonSchemaServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public static int $decorated = 0;

    public function decorate(array $schemas, Request $request, string $server): array
    {
        ++self::$decorated;
        if (!\in_array('genres', FeatureGateDecorator::features($request), true)) {
            unset($schemas['genres']);
        }

        return $schemas;
    }

    public function cacheKey(Request $request, string $server): array
    {
        return ['v' => 1, 'features' => FeatureGateDecorator::features($request)];
    }
}
